<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * project_users is a pivot table: one row is meant to be one user's role on one
 * project, but 2022_11_02_131753_create_project_users_table.php only added the
 * two foreign keys, never a unique (project_id, user_id) pair. A user could
 * therefore be attached to the same project several times with different roles,
 * which duplicates names in the contributor list, inflates member counts, and —
 * worst of all — keeps ProjectPolicy::update()/delete() granting management
 * rights: both check ->where('role', can_manage)->count(), so a stale
 * administrator row still authorises even after the "current" row was
 * downgraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicateMemberships();

        Schema::table('project_users', function (Blueprint $table) {
            $table->unique(['project_id', 'user_id']);
        });
    }

    /**
     * Collapses each (project_id, user_id) group down to a single row. The row
     * carrying the strongest role wins — dropping a membership outright would
     * silently lock a legitimate manager out of their own project, whereas a
     * role that is too generous stays visible in the members table and can be
     * downgraded by hand. Every removed row is logged so an admin can review.
     */
    private function deduplicateMemberships(): void
    {
        $ranks = $this->roleRanks();
        $kept = [];
        $toDelete = [];

        DB::table('project_users')->orderBy('id')->get(['id', 'project_id', 'user_id', 'role'])
            ->each(function ($row) use ($ranks, &$kept, &$toDelete) {
                $key = $row->project_id.':'.$row->user_id;
                $rank = $ranks[$row->role] ?? PHP_INT_MAX;

                if (! isset($kept[$key])) {
                    $kept[$key] = ['id' => $row->id, 'rank' => $rank, 'role' => $row->role];

                    return;
                }

                // A stronger role replaces the row kept so far; the previously
                // kept row is the one that gets dropped instead.
                if ($rank < $kept[$key]['rank']) {
                    $toDelete[] = $kept[$key]['id'];
                    $kept[$key] = ['id' => $row->id, 'rank' => $rank, 'role' => $row->role];

                    return;
                }

                $toDelete[] = $row->id;
            });

        if ($toDelete === []) {
            return;
        }

        Log::warning('Migrasi unique-index project_users: menghapus keanggotaan proyek duplikat', [
            'deleted_ids' => $toDelete,
            'kept' => array_map(fn (array $row) => $row['id'], $kept),
        ]);

        collect($toDelete)->chunk(500)->each(
            fn ($ids) => DB::table('project_users')->whereIn('id', $ids->all())->delete()
        );
    }

    /**
     * Role name => rank, lowest rank being the strongest. The manage role comes
     * first, then the default role, then whatever else the installation
     * configured; an unknown role ranks below all of them.
     */
    private function roleRanks(): array
    {
        $roles = array_keys(config('system.projects.affectations.roles.list', []));

        $ordered = array_values(array_unique(array_filter([
            config('system.projects.affectations.roles.can_manage'),
            config('system.projects.affectations.roles.default'),
            ...$roles,
        ])));

        return array_flip($ordered);
    }

    public function down(): void
    {
        Schema::table('project_users', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'user_id']);
        });
    }
};

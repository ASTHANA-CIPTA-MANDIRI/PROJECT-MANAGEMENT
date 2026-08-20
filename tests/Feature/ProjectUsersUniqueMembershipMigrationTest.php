<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectUsersUniqueMembershipMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_be_attached_twice_to_the_same_project(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->insertMembership($project->id, $user->id, 'employee');

        $this->expectException(QueryException::class);

        $this->insertMembership($project->id, $user->id, 'administrator');
    }

    public function test_the_same_user_can_still_join_several_projects(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->create();
        $second = Project::factory()->create();

        $this->insertMembership($first->id, $user->id, 'employee');
        $this->insertMembership($second->id, $user->id, 'administrator');

        $this->assertSame(2, DB::table('project_users')->where('user_id', $user->id)->count());
    }

    public function test_pre_existing_duplicates_are_collapsed_onto_the_strongest_role_and_logged(): void
    {
        $this->dropUniqueMembershipIndex();

        $project = Project::factory()->create();
        $user = User::factory()->create();

        $customerId = $this->insertMembership($project->id, $user->id, 'customer');
        $managerId = $this->insertMembership($project->id, $user->id, 'administrator');
        $employeeId = $this->insertMembership($project->id, $user->id, 'employee');

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($customerId, $employeeId) {
                return str_contains($message, 'keanggotaan proyek duplikat')
                    && $context['deleted_ids'] === [$customerId, $employeeId];
            });

        $rows = DB::table('project_users')->where('project_id', $project->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($managerId, $rows->first()->id);
        $this->assertSame('administrator', $rows->first()->role);
    }

    public function test_the_strongest_role_wins_even_when_it_was_recorded_first(): void
    {
        $this->dropUniqueMembershipIndex();

        $project = Project::factory()->create();
        $user = User::factory()->create();

        $managerId = $this->insertMembership($project->id, $user->id, 'administrator');
        $this->insertMembership($project->id, $user->id, 'employee');

        $this->runMigration();

        $this->assertSame(
            [$managerId],
            DB::table('project_users')->where('project_id', $project->id)->pluck('id')->all()
        );
    }

    public function test_distinct_memberships_are_left_untouched(): void
    {
        $this->dropUniqueMembershipIndex();

        $project = Project::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->insertMembership($project->id, $first->id, 'employee');
        $this->insertMembership($project->id, $second->id, 'customer');

        Log::spy();

        $this->runMigration();

        Log::shouldNotHaveReceived('warning');

        $this->assertSame(2, DB::table('project_users')->where('project_id', $project->id)->count());
    }

    /**
     * Simulates a database that still carries pre-migration duplicates by
     * removing the very index this migration adds.
     */
    private function dropUniqueMembershipIndex(): void
    {
        Schema::table('project_users', fn (Blueprint $table) => $table->dropUnique(['project_id', 'user_id']));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_20_000000_add_unique_index_to_project_users_table.php');
        $migration->up();
    }

    private function insertMembership(int $projectId, int $userId, string $role): int
    {
        return DB::table('project_users')->insertGetId([
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

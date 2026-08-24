<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * project_favorites and ticket_subscribers are pivot-style tables that only
 * ever got their foreign keys, never a uniqueness guarantee on the pair they
 * represent. The "favorite" and "subscribe" toggle actions read-then-write
 * ($model::where(...)->first() ?: $model::create(...)) with no transaction,
 * so two overlapping requests (a double click, two open tabs) can both see
 * "not favorited yet" and both insert - after that, one un-favorite click
 * deletes only one of the rows and the star stays lit. Both call sites are
 * updated alongside this migration to treat the resulting unique-constraint
 * violation as a no-op instead of a 500.
 *
 * ticket_relations and label_ticket are not touched here: label_ticket
 * already has a composite primary key (label_id, ticket_id) from its
 * creation migration, and ticket_relations is written by a Filament
 * relationship repeater whose save path this migration doesn't control -
 * enforcing it at the DB layer needs a form-level duplicate check first, to
 * avoid turning a duplicate-row nuisance into a raw exception on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe('project_favorites', ['user_id', 'project_id']);
        $this->dedupe('ticket_subscribers', ['user_id', 'ticket_id']);

        Schema::table('project_favorites', function (Blueprint $table) {
            $table->unique(['user_id', 'project_id']);
        });

        Schema::table('ticket_subscribers', function (Blueprint $table) {
            $table->unique(['user_id', 'ticket_id']);
        });
    }

    /**
     * Collapses duplicate rows sharing the same $columns down to the oldest
     * one. Every removed row is logged so an admin can review what was lost.
     */
    private function dedupe(string $table, array $columns): void
    {
        $seen = [];
        $toDelete = [];

        DB::table($table)->orderBy('id')->get(array_merge(['id'], $columns))
            ->each(function ($row) use ($columns, &$seen, &$toDelete) {
                $key = collect($columns)->map(fn ($column) => $row->{$column})->implode(':');

                if (isset($seen[$key])) {
                    $toDelete[] = $row->id;

                    return;
                }

                $seen[$key] = $row->id;
            });

        if ($toDelete === []) {
            return;
        }

        Log::warning("Migrasi unique-index {$table}: menghapus baris duplikat", [
            'deleted_ids' => $toDelete,
        ]);

        collect($toDelete)->chunk(500)->each(
            fn ($ids) => DB::table($table)->whereIn('id', $ids->all())->delete()
        );
    }

    public function down(): void
    {
        Schema::table('project_favorites', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'project_id']);
        });

        Schema::table('ticket_subscribers', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'ticket_id']);
        });
    }
};

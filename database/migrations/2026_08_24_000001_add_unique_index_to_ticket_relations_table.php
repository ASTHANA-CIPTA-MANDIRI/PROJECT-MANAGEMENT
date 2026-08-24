<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ticket_relations is the other pivot-style table left unguarded by
 * 2026_08_24_000000_add_unique_index_to_favorite_and_subscriber_pivots.php,
 * which deferred it because the row is written by TicketForm's relations
 * Repeater (a Filament ->relationship() sync), not a simple toggle action -
 * enforcing uniqueness at the DB layer needed a form-level duplicate check
 * first, so a duplicate row became a validation message instead of a raw
 * QueryException on save. TicketForm::relationsRepeater() now rejects a
 * (type, relation_id) pair already present in the submitted items, so this
 * migration only needs to guard against - and clean up - pre-existing
 * duplicates the form-level check didn't cover.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe('ticket_relations', ['ticket_id', 'relation_id', 'type']);

        Schema::table('ticket_relations', function (Blueprint $table) {
            $table->unique(['ticket_id', 'relation_id', 'type']);
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
        Schema::table('ticket_relations', function (Blueprint $table) {
            $table->dropUnique(['ticket_id', 'relation_id', 'type']);
        });
    }
};

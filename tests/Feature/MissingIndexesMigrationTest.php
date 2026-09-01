<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves 2026_08_05_000004_add_missing_indexes_for_hot_query_paths actually
 * created the indexes it claims to (project_id+status_id on tickets,
 * due_date on tickets, created_at on ticket_activities/ticket_comments/
 * ticket_hours). Uses SQLite's PRAGMA introspection since the test suite
 * runs on sqlite and Laravel 9's Schema facade has no portable getIndexes().
 */
class MissingIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function indexedColumnSets(string $table): array
    {
        $indexes = DB::select("PRAGMA index_list($table)");

        return collect($indexes)->map(function ($index) {
            $columns = DB::select("PRAGMA index_info({$index->name})");

            return collect($columns)->sortBy('seqno')->pluck('name')->all();
        })->all();
    }

    public function test_tickets_has_a_composite_project_id_status_id_index(): void
    {
        $this->assertContains(['project_id', 'status_id'], $this->indexedColumnSets('tickets'));
    }

    public function test_tickets_due_date_is_indexed(): void
    {
        $this->assertContains(['due_date'], $this->indexedColumnSets('tickets'));
    }

    public function test_ticket_activities_created_at_is_indexed(): void
    {
        $this->assertContains(['created_at'], $this->indexedColumnSets('ticket_activities'));
    }

    public function test_ticket_comments_created_at_is_indexed(): void
    {
        $this->assertContains(['created_at'], $this->indexedColumnSets('ticket_comments'));
    }

    public function test_ticket_hours_created_at_is_indexed(): void
    {
        $this->assertContains(['created_at'], $this->indexedColumnSets('ticket_hours'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketRelationUniquePivotMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_relation_type_cannot_be_inserted_twice_between_two_tickets(): void
    {
        $ticket = Ticket::factory()->create();
        $relation = Ticket::factory()->create();

        $this->insertRelation($ticket->id, $relation->id, 'related_to');

        $this->expectException(QueryException::class);

        $this->insertRelation($ticket->id, $relation->id, 'related_to');
    }

    public function test_the_same_ticket_pair_can_still_carry_different_relation_types(): void
    {
        $ticket = Ticket::factory()->create();
        $relation = Ticket::factory()->create();

        $this->insertRelation($ticket->id, $relation->id, 'related_to');
        $this->insertRelation($ticket->id, $relation->id, 'blocked_by');

        $this->assertSame(2, DB::table('ticket_relations')->where('ticket_id', $ticket->id)->count());
    }

    public function test_a_ticket_can_still_carry_the_same_relation_type_to_several_other_tickets(): void
    {
        $ticket = Ticket::factory()->create();
        $first = Ticket::factory()->create();
        $second = Ticket::factory()->create();

        $this->insertRelation($ticket->id, $first->id, 'related_to');
        $this->insertRelation($ticket->id, $second->id, 'related_to');

        $this->assertSame(2, DB::table('ticket_relations')->where('ticket_id', $ticket->id)->count());
    }

    public function test_pre_existing_relation_duplicates_are_collapsed_and_logged(): void
    {
        $this->dropUniqueIndex('ticket_relations', ['ticket_id', 'relation_id', 'type']);

        $ticket = Ticket::factory()->create();
        $relation = Ticket::factory()->create();

        $keptId = $this->insertRelation($ticket->id, $relation->id, 'related_to');
        $duplicateId = $this->insertRelation($ticket->id, $relation->id, 'related_to');

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($duplicateId) {
                return str_contains($message, 'ticket_relations')
                    && $context['deleted_ids'] === [$duplicateId];
            });

        $rows = DB::table('ticket_relations')->where('ticket_id', $ticket->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keptId, $rows->first()->id);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Simulates a database that still carries pre-migration duplicates by
     * removing the very index this migration adds.
     */
    private function dropUniqueIndex(string $table, array $columns): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($columns));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_24_000001_add_unique_index_to_ticket_relations_table.php');
        $migration->up();
    }

    private function insertRelation(int $ticketId, int $relationId, string $type): int
    {
        return DB::table('ticket_relations')->insertGetId([
            'ticket_id' => $ticketId,
            'relation_id' => $relationId,
            'type' => $type,
            'sort' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

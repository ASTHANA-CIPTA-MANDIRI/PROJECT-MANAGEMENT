<?php

namespace Tests\Unit\Helpers;

use App\Helpers\KanbanScrumHelper;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketRelation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KanbanScrumHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_records_eager_loads_relations_instead_of_one_query_per_ticket(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->create(['owner_id' => $user->id]);
        $tickets = Ticket::factory()->count(3)->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
        ]);

        foreach ($tickets as $ticket) {
            TicketRelation::create([
                'ticket_id' => $ticket->id,
                'type' => 'related_to',
                'relation_id' => $ticket->id,
            ]);
        }

        $helper = new class
        {
            use KanbanScrumHelper;
        };
        $helper->project = $project;

        $relationQueries = 0;
        DB::listen(function ($query) use (&$relationQueries) {
            if (str_contains($query->sql, 'ticket_relations')) {
                $relationQueries++;
            }
        });

        $records = $helper->getRecords();

        $this->assertCount(3, $records);
        // One eager-load query for all tickets, not one per ticket (N+1).
        $this->assertSame(1, $relationQueries);
    }
}

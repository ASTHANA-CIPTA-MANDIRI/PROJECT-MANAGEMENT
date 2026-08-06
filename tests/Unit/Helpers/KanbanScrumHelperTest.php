<?php

namespace Tests\Unit\Helpers;

use App\Helpers\KanbanScrumHelper;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketRelation;
use App\Models\TicketStatus;
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

    public function test_get_statuses_uses_one_grouped_count_instead_of_one_query_per_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->create(['owner_id' => $user->id]);
        $statuses = TicketStatus::factory()->count(3)->create();
        Ticket::factory()->count(2)->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'status_id' => $statuses[0]->id,
        ]);
        Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'status_id' => $statuses[1]->id,
        ]);

        $helper = new class
        {
            use KanbanScrumHelper;
        };
        $helper->project = $project;

        $ticketCountQueries = 0;
        DB::listen(function ($query) use (&$ticketCountQueries) {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"tickets"')) {
                $ticketCountQueries++;
            }
        });

        $result = $helper->getStatuses()->keyBy('id');

        // One grouped COUNT query for all statuses, not one per status (N+1).
        $this->assertSame(1, $ticketCountQueries);
        $this->assertSame(2, $result[$statuses[0]->id]['size']);
        $this->assertSame(1, $result[$statuses[1]->id]['size']);
        $this->assertSame(0, $result[$statuses[2]->id]['size']);
    }
}

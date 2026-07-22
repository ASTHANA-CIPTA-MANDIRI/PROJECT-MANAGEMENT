<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TicketResource;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class QueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // --------------------------------------------- project statistics cache

    public function test_statistics_returns_the_expected_counts(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->count(3)->create(['project_id' => $project->id]);

        $stats = $project->statistics();

        $this->assertSame(3, $stats['tickets']);
        $this->assertArrayHasKey('contributors', $stats);
        $this->assertArrayHasKey('logged_hours', $stats);
    }

    public function test_statistics_are_cached(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertSame(2, $project->statistics()['tickets']);

        // Insert a ticket directly (bypassing model events) so only the cache
        // decides the result: it must still read the cached value.
        DB::table('tickets')->insert([
            'name' => 'Silent', 'content' => 'x', 'owner_id' => $project->owner_id,
            'status_id' => Ticket::factory()->create()->status_id, 'project_id' => $project->id,
            'code' => 'X-99', 'order' => 99, 'type_id' => 1, 'priority_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(2, $project->statistics()['tickets'], 'value should still be cached');
    }

    public function test_forget_statistics_clears_the_cache(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->create(['project_id' => $project->id]);

        $project->statistics();
        $this->assertTrue(Cache::has($project->statisticsCacheKey()));

        $project->forgetStatistics();

        $this->assertFalse(Cache::has($project->statisticsCacheKey()));
    }

    public function test_creating_a_ticket_invalidates_the_cached_statistics(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame(1, $project->statistics()['tickets']);

        // Creating through the model fires the invalidation hook.
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame(2, $project->statistics()['tickets']);
    }

    public function test_logging_hours_invalidates_the_cached_statistics(): void
    {
        $project = Project::factory()->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertEqualsWithDelta(0, $project->statistics()['logged_hours'], 0.001);

        TicketHour::factory()->hours(4)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(4, $project->statistics()['logged_hours'], 0.001);
    }

    // ----------------------------------------------- eager loading (N+1) proof

    public function test_project_listing_query_eager_loads_relations(): void
    {
        User::factory()->create(); // extra users around
        $owner = User::factory()->create();
        Project::factory()->count(5)->create(['owner_id' => $owner->id]);

        DB::connection()->enableQueryLog();

        $projects = ProjectResource::getEloquentQuery()->get();
        foreach ($projects as $project) {
            $project->owner;    // eager-loaded
            $project->status;   // eager-loaded
            $project->users;    // eager-loaded
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // Without eager loading this would be 1 + 5*3 = 16+ queries. With it,
        // the relation count is constant regardless of the number of projects.
        $this->assertLessThanOrEqual(8, $queryCount, "Expected eager loading, ran {$queryCount} queries");
    }

    public function test_ticket_listing_query_eager_loads_relations(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->count(5)->create(['project_id' => $project->id]);

        DB::connection()->enableQueryLog();

        $tickets = TicketResource::getEloquentQuery()->get();
        foreach ($tickets as $ticket) {
            $ticket->owner;
            $ticket->status;
            $ticket->type;
            $ticket->priority;
            $ticket->project;
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // 1 tickets + 5 eager-loaded relations = ~6, not 1 + 5*5 = 26.
        $this->assertLessThanOrEqual(10, $queryCount, "Expected eager loading, ran {$queryCount} queries");
    }

    public function test_time_logged_widget_avoids_per_row_hour_queries(): void
    {
        $project = Project::factory()->create();
        $tickets = Ticket::factory()->count(4)->create(['project_id' => $project->id]);
        foreach ($tickets as $ticket) {
            TicketHour::factory()->count(2)->create(['ticket_id' => $ticket->id]);
        }

        DB::connection()->enableQueryLog();

        // Mirrors TicketTimeLogged::getData's query: a single aggregate query.
        Ticket::query()->has('hours')->withSum('hours', 'value')->limit(10)
            ->get(['id', 'code'])
            ->each(fn (Ticket $t) => $t->hours_sum_value);

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // One query total: the SUM is computed in SQL, not per ticket.
        $this->assertSame(1, $queryCount, "Expected a single aggregate query, ran {$queryCount}");
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\Kanban;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TicketResource;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\TicketRelation;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class QueryOptimizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

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

    /**
     * user-avatar.blade.php used to read ->ticketsOwned / ->ticketsResponsible /
     * ->projectsOwning / ->projectsAffected as properties (lazy-loading and
     * hydrating every row) just to merge/unique/count them in PHP. The
     * accessors must give the same, deduped answer via a single COUNT query.
     */
    public function test_tickets_and_projects_counts_are_correct_and_deduped(): void
    {
        $user = User::factory()->create();

        // Owned and responsible on the same ticket must count once, not twice.
        Ticket::factory()->create(['owner_id' => $user->id, 'responsible_id' => $user->id]);
        Ticket::factory()->create(['owner_id' => $user->id]);
        Ticket::factory()->create(['responsible_id' => $user->id]);

        $this->assertSame(3, $user->ticketsCount);

        // Owning a project and also being a pivot member of it must count once.
        $ownedAndMember = Project::factory()->create(['owner_id' => $user->id]);
        $ownedAndMember->users()->attach($user->id, ['role' => 'member']);
        Project::factory()->create(['owner_id' => $user->id]);
        Project::factory()->create()->users()->attach($user->id, ['role' => 'member']);

        $this->assertSame(3, $user->projectsCount);
    }

    /**
     * The old implementation ran SELECT * on every ticket/project the user
     * touched just to count them - unbounded per-render memory for a user
     * with thousands of tickets. The accessor must never hydrate those rows.
     */
    public function test_tickets_and_projects_counts_never_hydrate_full_rows(): void
    {
        $user = User::factory()->create();
        Ticket::factory()->count(5)->create(['owner_id' => $user->id]);
        Project::factory()->count(5)->create(['owner_id' => $user->id]);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $user->ticketsCount;
        $user->projectsCount;

        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        DB::connection()->disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn (string $sql) => str_starts_with($sql, 'select * from "tickets"')
                || str_starts_with($sql, 'select * from "projects"')),
            'no query should fetch full ticket/project rows: '.$queries->implode(' | ')
        );
        $this->assertTrue(
            $queries->every(fn (string $sql) => str_contains($sql, 'count(')),
            'every query should be an aggregate count: '.$queries->implode(' | ')
        );
    }

    /**
     * TicketObserver::updating() used to re-fetch the ticket it was already
     * handed (Ticket::where('id', $ticket->id)->first()) just to read the
     * pre-update status/sprint. Eloquent already carries those as-loaded
     * values via getOriginal(); no extra SELECT should run.
     */
    public function test_updating_a_ticket_does_not_re_query_its_original_row(): void
    {
        $ticket = Ticket::factory()->create();
        $newStatus = TicketStatus::factory()->create();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $ticket->update(['status_id' => $newStatus->id]);

        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        DB::connection()->disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn (string $sql) => str_starts_with($sql, 'select * from "tickets" where "id"')),
            'updating a ticket should not re-select its own row: '.$queries->implode(' | ')
        );
    }

    /**
     * TicketResource::getEloquentQuery() renders an avatar (with ticket/project
     * counts) for the owner and responsible of every row. Without eager-loaded
     * counts that's 4 extra queries per unique user; this must stay constant.
     */
    public function test_ticket_listing_query_eager_loads_avatar_counts(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create();
        Ticket::factory()->count(5)->create(['project_id' => $project->id, 'owner_id' => $owner->id]);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $tickets = TicketResource::getEloquentQuery()->get();
        foreach ($tickets as $ticket) {
            $ticket->owner->ticketsCount;
            $ticket->owner->projectsCount;
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertLessThanOrEqual(10, $queryCount, "Expected eager-loaded counts, ran {$queryCount} queries");
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

    /**
     * TicketObserver::creating() read $project->tickets as a property, i.e.
     * SELECT * FROM tickets WHERE project_id = ? — every row of the project
     * hydrated into a model — only to read one column. On a busy project that
     * ran on every single insert, inside the import/API transaction.
     */
    public function test_creating_a_ticket_does_not_load_every_ticket_of_the_project(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->count(10)->create(['project_id' => $project->id]);

        DB::connection()->enableQueryLog();

        Ticket::factory()->create(['project_id' => $project->id]);

        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        DB::connection()->disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $sql) => str_contains($sql, 'max(') && str_contains($sql, 'tickets')),
            'the next order should come from an aggregate'
        );
        $this->assertFalse(
            $queries->contains(fn (string $sql) => str_starts_with($sql, 'select * from "tickets"')),
            'no query should fetch every ticket row: '.$queries->implode(' | ')
        );
    }

    public function test_latest_projects_widget_eager_loads_cover_media(): void
    {
        Storage::fake('media');
        $owner = User::factory()->create();
        $projects = Project::factory()->count(5)->create(['owner_id' => $owner->id]);
        // Give a couple of them a real cover so the media-present path runs too.
        foreach ($projects->take(2) as $project) {
            $project->addMediaFromString('img')->usingFileName('cover.png')->toMediaCollection();
        }

        DB::connection()->enableQueryLog();

        // Mirrors LatestProjects::getTableQuery's eager-load set.
        $rows = Project::query()->with(['owner', 'status', 'media'])->limit(5)->get();
        foreach ($rows as $project) {
            $project->cover; // reads the media collection
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // Constant regardless of row count: without eager-loaded media each
        // cover access would add a query per project.
        $this->assertLessThanOrEqual(4, $queryCount, "Expected eager-loaded media, ran {$queryCount} queries");
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

    public function test_timesheet_time_logged_table_eager_loads_relations(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->count(5)->create(['ticket_id' => $ticket->id]);

        DB::connection()->enableQueryLog();

        // Mirrors Livewire\Timesheet\TimeLogged::getTableQuery's eager-load set.
        $hours = $ticket->hours()->with(['user', 'activity', 'ticket'])->getQuery()->get();
        foreach ($hours as $hour) {
            $hour->user;
            $hour->activity;
            $hour->ticket;
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // 1 hours query + 3 eager-loaded relations = 4, not 1 + 5*3 = 16.
        $this->assertLessThanOrEqual(4, $queryCount, "Expected eager loading, ran {$queryCount} queries");
    }

    // ------------------------------------------------------ cached accessors

    /**
     * Eloquent caches object attribute values by default, so this was already
     * a single query while a sprint was running. null is not an object
     * though, so a project between sprints re-ran the lookup on every read -
     * and the scrum page reads it about six times per render.
     */
    public function test_reading_the_current_sprint_of_a_project_between_sprints_runs_one_query(): void
    {
        $project = Project::factory()->scrum()->create();
        Sprint::factory()->ended()->create(['project_id' => $project->id]);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $project->currentSprint;
            $project->nextSprint;
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertNull($project->currentSprint);
        $this->assertSame(1, $queryCount, "Expected the accessors to cache, ran {$queryCount} queries");
    }

    public function test_the_running_sprint_is_still_found_and_cached(): void
    {
        $project = Project::factory()->scrum()->create();
        $running = Sprint::factory()->started()->create(['project_id' => $project->id]);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $project->currentSprint;
        }

        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertSame($running->id, $project->currentSprint->id);
        $this->assertSame(1, $queryCount, "Expected the accessor to cache, ran {$queryCount} queries");
    }

    /**
     * The cache lives on the model instance, so re-reading the project picks
     * up a sprint that started or ended in the meantime.
     */
    public function test_a_freshly_fetched_project_sees_the_new_current_sprint(): void
    {
        $project = Project::factory()->scrum()->create();
        $running = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->assertSame($running->id, $project->currentSprint->id);

        $running->update(['ended_at' => now()]);
        $next = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->assertSame($next->id, $project->fresh()->currentSprint->id);
    }

    // ------------------------------------------------ kanban / scrum board

    /**
     * Build a board's worth of cards, each with logged hours and a relation
     * to another ticket - the two things the card template reads.
     */
    private function boardWithCards(User $owner, int $cards): Project
    {
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $status = TicketStatus::factory()->default()->create(['project_id' => null]);

        for ($i = 0; $i < $cards; $i++) {
            $ticket = Ticket::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $owner->id,
                'status_id' => $status->id,
            ]);
            TicketHour::factory()->count(2)->create(['ticket_id' => $ticket->id]);
            TicketRelation::create([
                'ticket_id' => $ticket->id,
                'relation_id' => Ticket::factory()->create(['project_id' => $project->id])->id,
                'type' => config('system.tickets.relations.default'),
            ]);
        }

        return $project;
    }

    /**
     * Queries run by one board render. The page is mounted first and outside
     * the measurement: booting a Filament page runs its own fixed set of
     * permission/settings queries, which would drown out the per-card cost
     * this is here to catch.
     */
    private function boardQueryCount(Project $project): int
    {
        $board = Livewire::test(Kanban::class, ['project' => $project])->instance();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        // Touch what the card template reads, so a missing eager load shows up
        // as the extra query it would be at render time.
        foreach ($board->getRecords() as $record) {
            $record['totalLoggedHours'];
            foreach ($record['relations'] as $relation) {
                $relation->relation->code;
            }
        }

        $count = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return $count;
    }

    /**
     * getRecords() eager-loaded `relations` but not `relations.relation`, and
     * never loaded the hours the card footer reads - so every card cost one
     * query for its logged time plus one per relation. The board re-renders on
     * every Livewire interaction and every broadcast, so that multiplied fast.
     */
    public function test_the_board_does_not_run_extra_queries_per_card(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket']);
        $this->actingAs($user);

        $small = $this->boardWithCards($user, 2);
        $large = $this->boardWithCards($user, 8);

        $smallCount = $this->boardQueryCount($small);
        $largeCount = $this->boardQueryCount($large);

        // Four times the cards must cost the same number of queries: without
        // the eager loads this was 2 extra per card (hours + relation).
        $this->assertSame(
            $smallCount,
            $largeCount,
            "Board queries scale with card count: {$smallCount} for 2 cards, {$largeCount} for 8"
        );
    }
}

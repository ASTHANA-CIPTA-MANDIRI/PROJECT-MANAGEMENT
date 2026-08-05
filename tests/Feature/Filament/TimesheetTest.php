<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TimesheetResource\Pages\ListTimesheet;
use App\Filament\Widgets\Timesheet\ActivitiesReport;
use App\Filament\Widgets\Timesheet\MonthlyReport;
use App\Filament\Widgets\Timesheet\WeeklyReport;
use App\Models\Activity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the timesheet listing and the three report widgets
 * (Weekly/Monthly/Activities). The widgets now aggregate with portable
 * query-builder + Carbon grouping, so they run on the SQLite test connection.
 */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = [
            'List timesheet data', 'View timesheet dashboard',
            'List projects', 'View project', 'List tickets', 'View ticket',
            'List activities', 'View activity',
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Reporter']);
        $role->syncPermissions($names);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    private function logHours(int $entries = 3): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $activity = Activity::factory()->create();

        for ($i = 0; $i < $entries; $i++) {
            $ticket = Ticket::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $this->user->id,
            ]);

            TicketHour::factory()->hours(2)->create([
                'ticket_id' => $ticket->id,
                'user_id' => $this->user->id,
                'activity_id' => $activity->id,
                'created_at' => now()->subDays($i),
            ]);
        }
    }

    public function test_the_timesheet_list_page_renders(): void
    {
        $this->logHours();

        Livewire::test(ListTimesheet::class)->assertSuccessful();
    }

    public function test_the_timesheet_list_page_renders_when_empty(): void
    {
        Livewire::test(ListTimesheet::class)->assertSuccessful();
    }

    public function test_the_timesheet_list_page_renders_unclassified_hours(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);
        TicketHour::factory()->hours(3)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'activity_id' => null,
        ]);

        Livewire::test(ListTimesheet::class)->assertSuccessful();
    }

    public function test_logged_hours_are_scoped_to_their_user(): void
    {
        $this->logHours(2);
        $other = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $other->id]);
        TicketHour::factory()->hours(5)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $other->id,
        ]);

        $this->assertEqualsWithDelta(4.0, $this->user->fresh()->totalLoggedInHours, 0.001);
        $this->assertEqualsWithDelta(5.0, $other->fresh()->totalLoggedInHours, 0.001);
    }

    /**
     * TicketHourPolicy only lets a user view/edit/delete their own entries;
     * the listing must not leak other users' hours into the table either.
     */
    public function test_the_timesheet_list_only_shows_the_current_users_own_entries(): void
    {
        $this->logHours(1);
        $ownEntry = TicketHour::where('user_id', $this->user->id)->sole();

        $other = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $other->id]);
        $othersEntry = TicketHour::factory()->hours(5)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $other->id,
        ]);

        Livewire::test(ListTimesheet::class)
            ->assertCanSeeTableRecords([$ownEntry])
            ->assertCanNotSeeTableRecords([$othersEntry]);
    }

    /**
     * The edit route/page must reject access to someone else's entry, not
     * just hide it from the list. Since the eloquent query is scoped to the
     * current user (TicketHourPolicy::update() would deny it anyway), the
     * record simply cannot be resolved for a foreign route key.
     */
    public function test_editing_someone_elses_logged_hours_is_forbidden(): void
    {
        $other = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $other->id]);
        $othersEntry = TicketHour::factory()->hours(5)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $other->id,
        ]);

        try {
            Livewire::test(
                \App\Filament\Resources\TimesheetResource\Pages\EditTimesheet::class,
                ['record' => $othersEntry->getRouteKey()]
            );
            $this->fail('Expected resolving another user\'s timesheet entry to fail.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('No query results for model', $e->getMessage());
        }
    }

    // ----------------------------------------------------------- report widgets

    /**
     * Invoke a widget's protected getData() on the SQLite connection.
     */
    private function widgetData(object $widget): array
    {
        $method = new \ReflectionMethod($widget, 'getData');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }

    private function logHoursOn(\Carbon\Carbon $when, float $value, ?int $activityId = null): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);
        TicketHour::factory()->hours($value)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'activity_id' => $activityId,
            'created_at' => $when,
        ]);
    }

    public function test_weekly_report_aggregates_the_current_week(): void
    {
        $this->logHoursOn(now(), 2);
        $this->logHoursOn(now(), 3);

        $data = $this->widgetData(new WeeklyReport);

        $this->assertCount(7, $data['labels']);
        $this->assertEqualsWithDelta(5.0, array_sum($data['datasets'][0]['data']), 0.001);
    }

    public function test_weekly_report_does_not_hang_on_a_blank_filter(): void
    {
        $widget = new WeeklyReport;
        $widget->filter = '';

        $data = $this->widgetData($widget);

        $this->assertSame([], $data['labels']);
        $this->assertSame([], $data['datasets'][0]['data']);
    }

    public function test_monthly_report_aggregates_the_current_year(): void
    {
        $this->logHoursOn(now(), 2);
        $this->logHoursOn(now(), 4);
        $this->logHoursOn(now()->copy()->subYears(2), 9); // different year, excluded

        $data = $this->widgetData(new MonthlyReport);

        $this->assertCount(12, $data['labels']);
        $this->assertEqualsWithDelta(6.0, array_sum($data['datasets'][0]['data']), 0.001);
    }

    public function test_activities_report_groups_by_activity(): void
    {
        $activity = Activity::factory()->create(['name' => 'Development']);
        $this->logHoursOn(now(), 2, $activity->id);
        $this->logHoursOn(now(), 3, $activity->id);
        $this->logHoursOn(now(), 1, null); // unclassified

        $data = $this->widgetData(new ActivitiesReport);

        $this->assertContains('Development', $data['labels']);
        $this->assertContains(__('No activity'), $data['labels']);
        $this->assertEqualsWithDelta(6.0, array_sum($data['datasets'][0]['data']), 0.001);
    }

    public function test_report_widgets_render_through_livewire(): void
    {
        $this->logHours();

        Livewire::test(WeeklyReport::class)->assertSuccessful();
        Livewire::test(MonthlyReport::class)->assertSuccessful();
        Livewire::test(ActivitiesReport::class)->assertSuccessful();
    }
}

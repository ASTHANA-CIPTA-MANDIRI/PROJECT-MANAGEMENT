<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TimesheetDashboard;
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
        // Weekdays [0, a] + weekend [a, a + b] end at the month's total, whatever day "now" is.
        $total = collect($data['datasets'])
            ->flatMap(fn ($dataset) => $dataset['data'])
            ->filter()
            ->sum(fn (array $range) => $range[1] - $range[0]);
        $this->assertEqualsWithDelta(6.0, $total, 0.001);
    }

    public function test_monthly_report_splits_weekday_and_weekend_hours(): void
    {
        $this->logHoursOn(\Carbon\Carbon::create(2026, 3, 4, 10), 2); // Wednesday
        $this->logHoursOn(\Carbon\Carbon::create(2026, 3, 7, 10), 3); // Saturday
        $this->logHoursOn(\Carbon\Carbon::create(2026, 3, 8, 10), 1.5); // Sunday

        $widget = new MonthlyReport;
        $widget->filter = '2026';
        $data = $this->widgetData($widget);

        $this->assertSame(__('Weekdays'), $data['datasets'][0]['label']);
        $this->assertSame(__('Weekend'), $data['datasets'][1]['label']);
        $this->assertSame([0.0, 2.0], $data['datasets'][0]['data'][2]);
        // Weekend starts where the weekday part ends and is 3 + 1.5 hours long.
        $this->assertSame([2.0, 6.5], $data['datasets'][1]['data'][2]);
        // A month without hours leaves both parts out instead of drawing a sliver.
        $this->assertNull($data['datasets'][0]['data'][0]);
        $this->assertNull($data['datasets'][1]['data'][0]);
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

    public function test_dashboard_summary_totals_only_the_users_own_hours(): void
    {
        $activity = Activity::factory()->create(['name' => 'Development']);
        $other = Activity::factory()->create(['name' => 'Meeting']);
        $this->logHoursOn(now(), 2, $activity->id);
        $this->logHoursOn(now(), 3, $activity->id);
        $this->logHoursOn(now(), 1, $other->id);
        $this->logHoursOn(now()->subYears(2), 9, $other->id); // other year, excluded

        // Someone else's hours never count.
        $stranger = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $stranger->id]);
        TicketHour::factory()->hours(50)->create(['ticket_id' => $ticket->id, 'user_id' => $stranger->id, 'created_at' => now()]);

        $summary = Livewire::test(TimesheetDashboard::class)->assertSee(__('This week'))->instance()->summary();

        $this->assertEqualsWithDelta(6.0, $summary['year'], 0.001);
        $this->assertEqualsWithDelta(6.0, $summary['month'], 0.001);
        $this->assertEqualsWithDelta(6.0, $summary['week'], 0.001);
        $this->assertSame('Development', $summary['topActivity']);
        $this->assertEqualsWithDelta(5.0, $summary['topActivityHours'], 0.001);
    }

    public function test_dashboard_summary_is_empty_without_any_hours(): void
    {
        $summary = Livewire::test(TimesheetDashboard::class)->instance()->summary();

        $this->assertSame(0.0, $summary['year']);
        $this->assertNull($summary['topActivity']);
    }

    public function test_report_labels_are_translated_and_still_complete(): void
    {
        $months = $this->widgetData(new MonthlyReport)['labels'];
        $this->assertCount(12, $months);
        $this->assertSame(\Carbon\Carbon::create(2000, 1, 1)->translatedFormat('F'), $months[0]);

        $week = $this->widgetData(new WeeklyReport)['labels'];
        $this->assertCount(7, $week);
        $this->assertDoesNotMatchRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $week[0]);
    }
}

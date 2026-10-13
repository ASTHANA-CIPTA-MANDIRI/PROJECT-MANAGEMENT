<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TimesheetExport;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TimesheetExport used whereBetween('created_at', [$start, $end]) with a bare
 * date string for end_date - MySQL/SQLite read that as end_date 00:00:00, so
 * every entry logged later that same day (afternoon/evening) was silently
 * dropped from the export.
 */
class TimesheetExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = ['List timesheet data'];
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

    private function logHourAt(string $dateTime): TicketHour
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $this->user->id]);

        return TicketHour::factory()->hours(2)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'created_at' => $dateTime,
        ]);
    }

    private function exportedRows(string $start, string $end): \Illuminate\Support\Collection
    {
        return (new \App\Exports\TimesheetExport([
            'start_date' => $start,
            'end_date' => $end,
        ]))->collection();
    }

    public function test_entry_on_the_first_moment_of_the_start_date_is_included(): void
    {
        $this->logHourAt('2026-08-20 00:00:01');

        $rows = $this->exportedRows('2026-08-20', '2026-08-22');

        $this->assertCount(1, $rows);
    }

    public function test_entry_in_the_middle_of_the_range_is_included(): void
    {
        $this->logHourAt('2026-08-21 12:00:00');

        $rows = $this->exportedRows('2026-08-20', '2026-08-22');

        $this->assertCount(1, $rows);
    }

    public function test_entry_late_on_the_end_date_is_included(): void
    {
        $this->logHourAt('2026-08-22 23:59:59');

        $rows = $this->exportedRows('2026-08-20', '2026-08-22');

        $this->assertCount(1, $rows);
    }

    public function test_entry_after_the_end_date_is_excluded(): void
    {
        $this->logHourAt('2026-08-23 00:00:01');

        $rows = $this->exportedRows('2026-08-20', '2026-08-22');

        $this->assertCount(0, $rows);
    }

    public function test_entry_before_the_start_date_is_excluded(): void
    {
        $this->logHourAt('2026-08-19 23:59:59');

        $rows = $this->exportedRows('2026-08-20', '2026-08-22');

        $this->assertCount(0, $rows);
    }

    public function test_an_inverted_date_range_is_rejected_by_the_form(): void
    {
        Livewire::test(TimesheetExport::class)
            ->fillForm(['start_date' => '2026-08-22', 'end_date' => '2026-08-20'])
            ->call('create')
            ->assertHasFormErrors(['end_date']);
    }

    public function test_a_same_day_range_is_accepted_by_the_form(): void
    {
        Livewire::test(TimesheetExport::class)
            ->fillForm(['start_date' => '2026-08-20', 'end_date' => '2026-08-20'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    // ------------------------------------------------------- trial gating

    /**
     * Audit finding (pre-Fase 7): this page was only gated by the flat
     * 'List timesheet data' permission, invisible to Gate::before()'s
     * TRIAL_GATED_MODELS matching (no model argument) - a locked-out
     * Organization could still export their timesheet CSV indefinitely.
     * ->instance()->create() calls the method directly on the mounted
     * component so the actual return value (null vs a real download) can
     * be asserted - Livewire's call() helper does not expose it.
     */
    public function test_export_is_blocked_when_the_organizations_trial_has_ended(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
        $organization->users()->attach($this->user->id, ['role' => 'owner']);
        OrganizationContext::switch($this->user, $organization->id);

        $component = Livewire::test(TimesheetExport::class)
            ->fillForm(['start_date' => '2026-08-20', 'end_date' => '2026-08-20']);

        $this->assertNull($component->instance()->create());
    }

    /**
     * The mirror image: an active (non-expired) trial must not be blocked -
     * proves the check is a real gate, not one that always denies.
     */
    public function test_export_still_works_during_an_active_trial(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $organization->users()->attach($this->user->id, ['role' => 'owner']);
        OrganizationContext::switch($this->user, $organization->id);

        $component = Livewire::test(TimesheetExport::class)
            ->fillForm(['start_date' => '2026-08-20', 'end_date' => '2026-08-20']);

        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $component->instance()->create()
        );
    }

    public function test_quick_ranges_fill_both_dates_with_a_valid_period(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-14 10:00:00');

        $expected = [
            'week' => ['2026-10-12', '2026-10-18'],
            'month' => ['2026-10-01', '2026-10-31'],
            'last_month' => ['2026-09-01', '2026-09-30'],
            'year' => ['2026-01-01', '2026-12-31'],
        ];

        foreach ($expected as $range => [$start, $end]) {
            $component = Livewire::test(TimesheetExport::class)->call('setRange', $range);

            // The date picker may hydrate a time part; only the day matters.
            $this->assertSame($start, substr((string) $component->get('start_date'), 0, 10));
            $this->assertSame($end, substr((string) $component->get('end_date'), 0, 10));

            $component->call('create')->assertHasNoFormErrors();
        }

        // An unknown preset leaves the form alone.
        Livewire::test(TimesheetExport::class)->call('setRange', 'forever')->assertSet('start_date', null);

        \Carbon\Carbon::setTestNow();
    }

    public function test_the_file_contents_panel_lists_the_real_csv_columns(): void
    {
        $columns = Livewire::test(TimesheetExport::class)->instance()->exportColumns();

        $this->assertSame((new \App\Exports\TimesheetExport([]))->headings(), $columns);
        $this->assertContains('Hours', $columns);
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TimesheetExport;
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
}

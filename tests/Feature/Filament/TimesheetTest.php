<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TimesheetResource\Pages\ListTimesheet;
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
 * Covers the timesheet listing only.
 *
 * The three report widgets (Weekly/Monthly/Activities) are deliberately not
 * covered here: their queries are raw MySQL (DATE_FORMAT(), YEAR()), so they
 * cannot execute on the SQLite test connection. WeeklyReport is worse than a
 * plain failure - with an unset filter it hands empty dates to
 * CarbonPeriod::create(), which then iterates forever and hangs the runner.
 * Covering them needs either portable date expressions or a MySQL test service.
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
}

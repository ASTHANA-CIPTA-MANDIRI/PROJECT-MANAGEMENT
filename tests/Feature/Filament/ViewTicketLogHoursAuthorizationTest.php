<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * The "Log time" action's visible() closure turns out to be enforced
 * server-side too: Filament's Actions\Concerns\CanBeDisabled::isDisabled()
 * counts a hidden action as disabled, and both mountAction() and
 * callMountedAction() refuse to run a disabled action - so a crafted
 * mountAction/callMountedAction pair (no button rendered) already can't
 * create a TicketHour for someone who isn't owner/responsible or lacks
 * TicketHourPolicy::create(). action() now states that same condition itself
 * via abort_unless() rather than relying on the framework's indirect
 * guarantee - the same reasoning Attachments::perform() documents for its
 * own delete action.
 */
class ViewTicketLogHoursAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    /**
     * A project member who may view the ticket but is neither its owner nor
     * its responsible - can open the page, must not be able to log hours.
     */
    private function outsiderViewing(Ticket $ticket): User
    {
        $reader = $this->userWithPermissions(['List tickets', 'View ticket', 'List timesheet data']);
        $ticket->project->users()->attach($reader->id, ['role' => 'employee']);

        $this->actingAs($reader);

        return $reader;
    }

    private function callLogHours(Ticket $ticket)
    {
        return Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('mountAction', 'logHours')
            ->set('mountedActionData.time', 2)
            ->call('callMountedAction');
    }

    public function test_the_log_time_button_is_hidden_from_a_non_owner_non_responsible_viewer(): void
    {
        $ticket = Ticket::factory()->create();
        $this->outsiderViewing($ticket);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertDontSeeHtml("mountAction('logHours')");
    }

    /**
     * No 403 here: hiding the action already makes Filament refuse to mount
     * or call it, so the request is dropped before action()'s own
     * abort_unless() runs. What matters is that no TicketHour is created.
     */
    public function test_a_non_owner_non_responsible_viewer_cannot_log_hours(): void
    {
        $ticket = Ticket::factory()->create();
        $this->outsiderViewing($ticket);

        $this->callLogHours($ticket)->assertSuccessful();

        $this->assertSame(0, TicketHour::count());
    }

    public function test_the_ticket_owner_can_log_hours(): void
    {
        $owner = $this->userWithPermissions(['List tickets', 'View ticket', 'List timesheet data']);
        $project = Project::factory()->create();
        $project->users()->attach($owner->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $owner->id]);

        $this->actingAs($owner);

        $this->callLogHours($ticket)->assertSuccessful();

        $this->assertSame(1, TicketHour::count());
        $this->assertSame($owner->id, TicketHour::sole()->user_id);
    }

    public function test_the_ticket_responsible_can_log_hours(): void
    {
        $responsible = $this->userWithPermissions(['List tickets', 'View ticket', 'List timesheet data']);
        $project = Project::factory()->create();
        $project->users()->attach($responsible->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'responsible_id' => $responsible->id,
        ]);

        $this->actingAs($responsible);

        $this->callLogHours($ticket)->assertSuccessful();

        $this->assertSame(1, TicketHour::count());
    }

    /**
     * Owner/responsible alone is not enough either: TicketHourPolicy::create()
     * still requires the "List timesheet data" permission. Same silent-no-op
     * reasoning as the outsider case above.
     */
    public function test_the_owner_without_timesheet_permission_still_cannot_log_hours(): void
    {
        $owner = $this->userWithPermissions(['List tickets', 'View ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($owner->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $owner->id]);

        $this->actingAs($owner);

        $this->callLogHours($ticket)->assertSuccessful();

        $this->assertSame(0, TicketHour::count());
    }
}

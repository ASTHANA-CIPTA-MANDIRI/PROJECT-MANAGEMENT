<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Timesheet\TimeLogged;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * M-8: TimeLogged used to run with no authorization check of its own - the
 * parent ticket page enforces TicketPolicy::view(), but the component is
 * reachable independently of whichever page happened to render it, so it
 * must not rely on that alone.
 */
class TimeLoggedAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    /**
     * A project member with the given permissions, plus a ticket in it that
     * has one logged hour entry.
     *
     * @param  array<int, string>  $permissions
     * @return array{0: \App\Models\User, 1: Ticket}
     */
    private function memberWith(array $permissions): array
    {
        $user = $this->userWithPermissions($permissions);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        return [$user, $ticket];
    }

    public function test_a_project_member_can_see_the_logged_hours(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket']);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])
            ->assertSuccessful();
    }

    /**
     * view() is object-level, so someone with the permission but no
     * involvement in this particular ticket (not owner/responsible, no
     * project access) is refused outright - not just shown an empty table.
     */
    public function test_an_outsider_cannot_open_the_component_at_all(): void
    {
        $stranger = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket = Ticket::factory()->create();
        $this->actingAs($stranger);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])
            ->assertForbidden();
    }

    /**
     * The same crafted-request shape as TicketAttachmentsAuthorizationTest:
     * mount while allowed, then have the ticket become inaccessible (removed
     * from the project) and issue a follow-up Livewire request. booted() runs
     * on every request, not just the first, so this must still be refused.
     */
    public function test_losing_access_after_mount_is_still_refused_on_the_next_request(): void
    {
        [$user, $ticket] = $this->memberWith(['List tickets', 'View ticket']);
        $component = Livewire::test(TimeLogged::class, ['ticket' => $ticket]);

        $ticket->project->users()->detach($user->id);

        $component->call('$refresh')->assertForbidden();
    }

    public function test_the_tickets_owner_can_see_logged_hours_without_project_membership(): void
    {
        $owner = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        TicketHour::factory()->hours(1)->create(['ticket_id' => $ticket->id, 'user_id' => $owner->id]);
        $this->actingAs($owner);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])
            ->assertSuccessful();
    }
}

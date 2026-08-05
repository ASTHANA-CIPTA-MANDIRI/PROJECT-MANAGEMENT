<?php

namespace Tests\Feature\Policies;

use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class TicketPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    // ------------------------------------------------------------- viewAny

    public function test_listing_requires_the_list_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['List tickets'])->can('viewAny', Ticket::class));
    }

    public function test_listing_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('viewAny', Ticket::class));
    }

    // ---------------------------------------------------------------- view

    public function test_the_ticket_owner_can_view_it(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('view', $ticket));
    }

    public function test_the_responsible_user_can_view_it(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create(['responsible_id' => $user->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('view', $ticket));
    }

    public function test_the_project_owner_can_view_the_ticket(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('view', $ticket));
    }

    public function test_a_project_member_can_view_the_ticket(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('view', $ticket));
    }

    public function test_an_unrelated_user_cannot_view_the_ticket(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $ticket));
    }

    public function test_viewing_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $ticket));
    }

    // -------------------------------------------------------------- create

    public function test_creating_requires_the_create_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Create ticket'])->can('create', Ticket::class));
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('create', Ticket::class));
    }

    // -------------------------------------------------------------- update

    public function test_the_ticket_owner_can_update_it(): void
    {
        $user = $this->userWithPermissions(['Update ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('update', $ticket));
    }

    public function test_the_responsible_user_can_update_it(): void
    {
        $user = $this->userWithPermissions(['Update ticket']);
        $ticket = Ticket::factory()->create(['responsible_id' => $user->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('update', $ticket));
    }

    public function test_an_unrelated_user_cannot_update_the_ticket(): void
    {
        $user = $this->userWithPermissions(['Update ticket']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($user);

        $this->assertFalse($user->can('update', $ticket));
    }

    // -------------------------------------------------------------- delete

    public function test_deleting_requires_the_delete_permission(): void
    {
        $user = $this->userWithPermissions(['Delete ticket']);
        $ticket = Ticket::factory()->create();

        $this->assertTrue($user->can('delete', $ticket));
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('delete', Ticket::factory()->create()));
    }

    // ----------------------------------------------------------- deleteAny

    public function test_bulk_deleting_requires_the_delete_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Delete ticket'])->can('deleteAny', Ticket::class));
    }

    public function test_bulk_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('deleteAny', Ticket::class));
    }

    // ------------------------------------------- regression: guest context

    /**
     * The policy must decide for the $user it is handed, not for whoever
     * happens to be logged in. It previously read auth()->user()->id, which
     * fatals ("property id on null") whenever no session exists - e.g. in
     * queued jobs or console commands.
     */
    public function test_view_denies_an_unrelated_user_with_no_authenticated_session(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create();

        // No actingAs(): nobody is logged in.
        $this->assertFalse($user->can('view', $ticket));
    }

    public function test_update_denies_an_unrelated_user_with_no_authenticated_session(): void
    {
        $user = $this->userWithPermissions(['Update ticket']);
        $ticket = Ticket::factory()->create();

        $this->assertFalse($user->can('update', $ticket));
    }

    /**
     * A member check must follow the passed $user even when a *different*
     * user is authenticated.
     */
    public function test_view_follows_the_passed_user_not_the_logged_in_one(): void
    {
        $member = $this->userWithPermissions(['View ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $stranger = $this->userWithPermissions(['View ticket']);
        $this->actingAs($stranger);

        // $member is a project member, so the answer is about $member.
        $this->assertTrue($member->can('view', $ticket));
        // $stranger is unrelated, even though they are the logged-in user.
        $this->assertFalse($stranger->can('view', $ticket));
    }
}

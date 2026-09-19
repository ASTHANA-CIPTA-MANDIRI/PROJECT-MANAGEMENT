<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Models\Label;
use App\Models\Organization;
use App\Models\OrganizationSupportAction;
use App\Models\OrganizationSupportSession;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Read-only by construction (see the class docblock on OrganizationSupportView
 * for why): access requires both isSuperAdmin() AND an active
 * App\Support\SupportSessionContext session, and ending the session locks the
 * page back out immediately — mirroring PlatformOrganizationsTest's own
 * "hiding the nav entry is not the authorization" discipline.
 */
class OrganizationSupportViewTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    public function test_super_admin_with_an_active_session_sees_only_that_organizations_projects(): void
    {
        $admin = $this->superAdmin();

        $targetOrg = Organization::factory()->create(['name' => 'Target Co']);
        Project::factory()->create(['organization_id' => $targetOrg->id, 'name' => 'Target Project']);

        $otherOrg = Organization::factory()->create(['name' => 'Other Co']);
        Project::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Other Project']);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $targetOrg, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee('Target Project')
            ->assertDontSee('Other Project');
    }

    public function test_super_admin_without_an_active_session_is_denied(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertFalse(OrganizationSupportView::userCanAccessPage());
        Livewire::test(OrganizationSupportView::class)->assertForbidden();
    }

    public function test_a_non_super_admin_is_denied_regardless_of_session_state(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();
    }

    public function test_ending_the_session_clears_it_and_redirects(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('endSession')
            ->assertRedirect();

        $this->assertNull(SupportSessionContext::current($admin));
    }

    /**
     * Section 5-13 of the read-only Support Center enhancement: overview
     * counters, tickets, sprints, members and recent activity must all be
     * scoped to the organization under support — never another one, and
     * never fetched with Model::all() then filtered in PHP.
     */
    public function test_support_center_shows_only_the_supported_organizations_tickets_sprints_members_and_activity(): void
    {
        $admin = $this->superAdmin();

        $targetOrg = Organization::factory()->create(['name' => 'Target Co']);
        $targetOwner = User::factory()->create(['name' => 'Target Owner', 'email' => 'target-owner@example.com']);
        $targetOrg->users()->attach($targetOwner->id, ['role' => 'owner']);
        $targetProject = Project::factory()->create(['organization_id' => $targetOrg->id, 'name' => 'Target Project']);
        $targetStatusTodo = TicketStatus::factory()->default()->create(['project_id' => $targetProject->id]);
        $targetStatusDone = TicketStatus::factory()->final()->create(['project_id' => $targetProject->id]);
        $targetTicket = Ticket::factory()->create([
            'project_id' => $targetProject->id,
            'status_id' => $targetStatusTodo->id,
            'name' => 'Target Ticket',
        ]);
        $targetSprint = Sprint::factory()->started()->create(['project_id' => $targetProject->id, 'name' => 'Target Sprint']);
        TicketActivity::create([
            'ticket_id' => $targetTicket->id,
            'old_status_id' => $targetStatusTodo->id,
            'new_status_id' => $targetStatusDone->id,
            'user_id' => $targetOwner->id,
        ]);

        $otherOrg = Organization::factory()->create(['name' => 'Other Co']);
        $otherOwner = User::factory()->create(['name' => 'Other Owner', 'email' => 'other-owner@example.com']);
        $otherOrg->users()->attach($otherOwner->id, ['role' => 'owner']);
        $otherProject = Project::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Other Project']);
        $otherStatus = TicketStatus::factory()->create(['project_id' => $otherProject->id]);
        $otherTicket = Ticket::factory()->create([
            'project_id' => $otherProject->id,
            'status_id' => $otherStatus->id,
            'name' => 'Other Ticket',
        ]);
        Sprint::factory()->started()->create(['project_id' => $otherProject->id, 'name' => 'Other Sprint']);
        TicketActivity::create([
            'ticket_id' => $otherTicket->id,
            'old_status_id' => $otherStatus->id,
            'new_status_id' => $otherStatus->id,
            'user_id' => $otherOwner->id,
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $targetOrg, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee('Target Ticket')
            ->assertDontSee('Other Ticket')
            ->assertSee('Target Sprint')
            ->assertDontSee('Other Sprint')
            ->assertSee('Target Owner')
            ->assertDontSee('Other Owner')
            ->assertDontSee('other-owner@example.com');
    }

    /**
     * Read-only guarantee (section 14): the page renders no create/update/
     * delete controls anywhere — the only wire:click action on the whole
     * page is endSession().
     */
    public function test_support_center_renders_no_write_controls(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        // Filament's page layout always renders an empty, unused modal/form
        // shell (for table/form actions) regardless of whether the page
        // defines any — asserting its absence would be a false positive.
        // What actually matters for this read-only page is that endSession()
        // is the only wire:click this page's own view defines, and no
        // create/update/delete action of its own exists to click at all.
        Livewire::test(OrganizationSupportView::class)
            ->assertDontSee('wire:click="create', false)
            ->assertDontSee('wire:click="update', false)
            ->assertDontSee('wire:click="delete', false)
            ->assertSee('wire:click="endSession"', false);

        $this->assertFalse(method_exists(OrganizationSupportView::class, 'create'));
        $this->assertFalse(method_exists(OrganizationSupportView::class, 'update'));
        $this->assertFalse(method_exists(OrganizationSupportView::class, 'delete'));
    }

    /**
     * Data integrity (section 14/30): merely opening the support page must
     * never write to any tenant data — only session-tracking rows (the
     * support session itself) change.
     */
    public function test_opening_the_support_center_does_not_modify_tenant_data(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $projectUpdatedAt = $project->updated_at;
        $ticketUpdatedAt = $ticket->updated_at;
        $sprintUpdatedAt = $sprint->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)->assertSuccessful();

        $this->assertTrue($project->fresh()->updated_at->eq($projectUpdatedAt));
        $this->assertTrue($ticket->fresh()->updated_at->eq($ticketUpdatedAt));
        $this->assertTrue($sprint->fresh()->updated_at->eq($sprintUpdatedAt));
    }

    /**
     * Phase 1 of Support Action: picking the Support Action level must not
     * unlock anything on this page yet — no write authorization layer
     * exists until Phase 3, so a Support Action session is exactly as
     * read-only as a Read Only one today. This also proves the page still
     * renders (doesn't error) once `level` is a real column it reads.
     */
    public function test_a_support_action_level_session_is_still_fully_read_only(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertDontSee('wire:click="create', false)
            ->assertDontSee('wire:click="update', false)
            ->assertDontSee('wire:click="delete', false)
            ->assertSee('wire:click="endSession"', false);
    }

    /**
     * Security audit Finding #1 (HIGH): sprintStatusBreakdown(Sprint $sprint)
     * is reachable through a direct Livewire method call
     * ($wire.call('sprintStatusBreakdown', <id>)), which Livewire resolves
     * via implicit model binding (Livewire\ImplicitlyBoundMethod) exactly
     * like route-model-binding — completely bypassing sprints()'s own
     * organization-scoped list. This proves the fix (an explicit
     * organization_id re-check inside the method, abort_unless(..., 403))
     * closes it: a support session for Alpha must be able to process
     * Alpha's own sprint, but calling the same method with Beta's sprint id
     * must be rejected with 403 — not merely fail to render it in HTML.
     */
    public function test_sprint_status_breakdown_rejects_a_sprint_from_another_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $alphaOrg = Organization::factory()->create(['name' => 'Alpha']);
        $alphaProject = Project::factory()->create(['organization_id' => $alphaOrg->id]);
        $alphaSprint = Sprint::factory()->create(['project_id' => $alphaProject->id, 'name' => 'Alpha Sprint']);

        $betaOrg = Organization::factory()->create(['name' => 'Beta']);
        $betaProject = Project::factory()->create(['organization_id' => $betaOrg->id]);
        $betaSprint = Sprint::factory()->create(['project_id' => $betaProject->id, 'name' => 'Beta Sprint']);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $alphaOrg, 'Support request');

        // Alpha's own sprint (the one sprints() itself would have returned)
        // must still be processable — the fix must not break the intended
        // path.
        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStatusBreakdown', $alphaSprint->id)
            ->assertOk();

        // A crafted call with Beta's sprint id — never rendered by this
        // Alpha session's own sprints() list — must be rejected outright,
        // the same way Kanban::mount()/Scrum::mount() reject a project that
        // isn't the caller's to see.
        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStatusBreakdown', $betaSprint->id)
            ->assertForbidden();
    }

    /**
     * Security audit Finding #2 (HIGH): members() used to return the full
     * User model, which — regardless of what the Blade view chose to
     * render — carried `creation_token` (the account-activation secret
     * ValidateAccount::validateAccount() consumes to set a pending
     * member's password) in whatever the method returned. $hidden on User
     * only protects password/remember_token, not creation_token, so the
     * fix instead selects only the columns Support Center actually needs.
     * This asserts against the raw fetched attributes (not the rendered
     * HTML) so the test fails again if a future change widens the select
     * back to '*'.
     */
    public function test_members_never_fetches_the_account_activation_token(): void
    {
        $admin = $this->superAdmin();

        $organization = Organization::factory()->create();
        $member = User::factory()->create([
            'name' => 'Regular Member',
            'email' => 'regular-member@example.com',
            'creation_token' => 'super-secret-activation-token',
        ]);
        $organization->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        $fetchedMembers = Livewire::test(OrganizationSupportView::class)
            ->instance()
            ->members();

        $fetched = $fetchedMembers->firstOrFail(fn (User $user) => $user->is($member));

        $this->assertArrayNotHasKey('creation_token', $fetched->getAttributes());
        $this->assertSame('Regular Member', $fetched->name);
        $this->assertSame('regular-member@example.com', $fetched->email);
        $this->assertSame('member', $fetched->pivot->role);

        // The Support Center itself must still show the member correctly —
        // the fix must not have broken the existing view.
        Livewire::test(OrganizationSupportView::class)
            ->assertSee('Regular Member')
            ->assertSee('regular-member@example.com');
    }

    // -------------------------------------------------------------------
    // Phase 4 of Support Action: changeTicketStatus()
    // -------------------------------------------------------------------

    public function test_change_ticket_status_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->default()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertSuccessful();

        $this->assertSame($newStatus->id, $ticket->fresh()->status_id);

        // TicketObserver's existing side effect must still fire — this
        // action must go through the ordinary Eloquent save(), never
        // bypass it.
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'old_status_id' => $oldStatus->id,
            'new_status_id' => $newStatus->id,
            'user_id' => $admin->id,
        ]);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_status', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame('status_id', $action->field);
        $this->assertSame((string) $oldStatus->id, $action->old_value);
        $this->assertSame((string) $newStatus->id, $action->new_value);
    }

    public function test_change_ticket_status_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_status_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($owner);

        // No session exists for a non-Super-Admin at all, so the page
        // itself is already forbidden at mount — chaining ->call() onto an
        // already-forbidden mount is not a meaningful additional check
        // (see the identical reasoning on the other "no valid session"
        // cases below).
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    public function test_change_ticket_status_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);

        // No session started at all — mount itself is already forbidden.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    public function test_change_ticket_status_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        // The session is expired by the time this page is even mounted —
        // userCanAccessPage() already returns false, so the page itself is
        // forbidden before any action-specific check would run.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    public function test_change_ticket_status_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        // The session is already ended by the time this page is mounted —
        // same reasoning as the expired case above.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    public function test_change_ticket_status_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        // current($adminB) never finds adminA's session (super_admin_id
        // doesn't match), so userCanAccessPage() already fails at mount.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    /**
     * The core organization-boundary test: a Support Action session for
     * Organization A must not be able to reach Organization B's ticket at
     * all — not "reach it but get refused after loading", but never find
     * it in the first place — proven here via a direct Livewire method
     * call with Organization B's own ticket id, not through any rendered
     * UI.
     */
    public function test_change_ticket_status_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->customStatuses()->create(['organization_id' => $organizationA->id]);
        $newStatusA = TicketStatus::factory()->forProject($projectA)->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->customStatuses()->create(['organization_id' => $organizationB->id]);
        $oldStatusB = TicketStatus::factory()->forProject($projectB)->create();
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'status_id' => $oldStatusB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticketB->id, $newStatusA->id)
            ->assertForbidden();

        $this->assertSame($oldStatusB->id, $ticketB->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * The ticket itself is a legitimate target (Organization A, the
     * session's own organization) — only the *new* status value is
     * illegitimate (it belongs to a different project's custom status
     * set). A valid target must not be enough on its own; the new value
     * needs its own scope check.
     */
    public function test_change_ticket_status_rejects_a_status_belonging_to_a_different_project(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $projectOne = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $statusForProjectOne = TicketStatus::factory()->forProject($projectOne)->create();
        $ticket = Ticket::factory()->create(['project_id' => $projectOne->id, 'status_id' => $statusForProjectOne->id]);

        $projectTwo = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $statusForProjectTwo = TicketStatus::factory()->forProject($projectTwo)->create();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $statusForProjectTwo->id)
            ->assertForbidden();

        $this->assertSame($statusForProjectOne->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_status_rejects_a_nonexistent_status_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, 999999999)
            ->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Idempotency: picking the status the ticket already has must not
     * write, must not fire TicketObserver's status-change side effects
     * (it never even calls save()), and must not create a fake audit
     * record for a change that never happened.
     */
    public function test_change_ticket_status_is_a_no_op_when_the_new_status_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $status = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $status->id]);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $status->id)
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->id)->count());
    }

    // -------------------------------------------------------------------
    // Security fix: ticketStatusOptions() organization boundary
    // (MEDIUM finding from the Phase 4 security audit — same class of bug
    // as sprintStatusBreakdown() before it was fixed: a public method
    // taking an Eloquent-model parameter is reachable via a direct
    // Livewire call with a client-supplied id, regardless of what Blade
    // ever actually renders.)
    // -------------------------------------------------------------------

    /**
     * Same-organization case: not just "doesn't error" — the actual
     * content returned must be correct (the ticket's own project's status
     * options), proven independently of the direct-call path below.
     */
    public function test_ticket_status_options_returns_the_correct_statuses_for_a_ticket_in_the_current_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $statusOne = TicketStatus::factory()->forProject($project)->create(['name' => 'To Do', 'order' => 0]);
        $statusTwo = TicketStatus::factory()->forProject($project)->create(['name' => 'Done', 'order' => 1]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $statusOne->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        // Proves the direct-Livewire-call path (implicit model binding
        // resolving $ticket from the id) does not reject a legitimate
        // same-organization ticket — the exact regression that would
        // happen if the organization_id eager-load feeding the new
        // boundary check were missing (as it briefly was for
        // sprintStatusBreakdown()'s own equivalent fix).
        $component = Livewire::test(OrganizationSupportView::class)
            ->call('ticketStatusOptions', $ticket->id)
            ->assertOk();

        // Content correctness: the actual options returned must be this
        // ticket's own project's statuses, not merely "no exception".
        $options = $component->instance()->ticketStatusOptions($ticket);
        $this->assertSame([$statusOne->id, $statusTwo->id], $options->pluck('id')->all());
        $this->assertSame(['To Do', 'Done'], $options->pluck('name')->all());
    }

    /**
     * The finding itself: a Support Action session for Organization A must
     * not be able to read Organization B's ticket status options via a
     * direct Livewire call. A 403 response carries no method return value
     * at all (Livewire's exception handling replaces it with an error
     * response before `return` in the method body is ever reached), so
     * asserting forbidden() already proves no Organization B status data
     * is included in what the client receives.
     */
    public function test_ticket_status_options_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->customStatuses()->create(['organization_id' => $organizationB->id]);
        $statusB = TicketStatus::factory()->forProject($projectB)->create(['name' => 'Organization B Only Status']);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'status_id' => $statusB->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)
            ->call('ticketStatusOptions', $ticketB->id)
            ->assertForbidden()
            ->assertDontSee('Organization B Only Status');
    }

    /**
     * A nonexistent ticket id must not surface anything either — Livewire's
     * own implicit model binding already throws a ModelNotFoundException
     * before this method's body ever runs (confirmed empirically here, not
     * assumed: Livewire\ImplicitlyBoundMethod::getImplicitBinding() throws
     * it directly, and this test harness does not convert it into an HTTP
     * response the way it does for abort()'s HttpException elsewhere in
     * this file — so the correct assertion is that the exception itself is
     * thrown, not a response status). Either way, the important property
     * holds: this method's own body — the only place that could return
     * another organization's status data — never executes.
     */
    public function test_ticket_status_options_throws_for_a_nonexistent_ticket_id_without_reaching_the_method_body(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(OrganizationSupportView::class)
            ->call('ticketStatusOptions', 999999999);
    }

    // -------------------------------------------------------------------
    // Phase 6A of Support Action: changeTicketPriority() / priorityOptions()
    //
    // TicketPriority is organization-scoped (App\Models\Concerns\BelongsToOrganization,
    // ticket_priorities.organization_id — confirmed from the actual schema/
    // model, not assumed), unlike TicketStatus which is project-scoped —
    // so "wrong scope" here means a priority from a *different
    // organization*, not a different project within the same one.
    // -------------------------------------------------------------------

    public function test_change_ticket_priority_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticket->id, $newPriority->id)
            ->assertSuccessful();

        $this->assertSame($newPriority->id, $ticket->fresh()->priority_id);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_priority', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame($ticket->id, $action->target_id);
        $this->assertSame('priority_id', $action->field);
        $this->assertSame((string) $oldPriority->id, $action->old_value);
        $this->assertSame((string) $newPriority->id, $action->new_value);
    }

    public function test_change_ticket_priority_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticket->id, $newPriority->id)
            ->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_priority_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);

        // No session started at all — mount itself is already forbidden
        // (see the identical reasoning on changeTicketStatus()'s own
        // "no session" test).
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
    }

    public function test_change_ticket_priority_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        // Expired by the time the page is mounted — forbidden at mount.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
    }

    public function test_change_ticket_priority_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
    }

    public function test_change_ticket_priority_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $newPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
    }

    /**
     * The core organization-boundary test: a Support Action session for
     * Organization A must not be able to reach Organization B's ticket at
     * all via a direct Livewire method call.
     */
    public function test_change_ticket_priority_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $priorityA = TicketPriority::factory()->create(['organization_id' => $organizationA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $oldPriorityB = TicketPriority::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'priority_id' => $oldPriorityB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticketB->id, $priorityA->id)
            ->assertForbidden();

        $this->assertSame($oldPriorityB->id, $ticketB->fresh()->priority_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_priority_rejects_a_nonexistent_priority_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $oldPriority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $oldPriority->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticket->id, 999999999)
            ->assertForbidden();

        $this->assertSame($oldPriority->id, $ticket->fresh()->priority_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * "Wrong scope" for priority means a different *organization* (not a
     * different project within the same organization, as it did for
     * status) — TicketPriority has no project_id at all, only
     * organization_id.
     */
    public function test_change_ticket_priority_rejects_a_priority_belonging_to_a_different_organization(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        $oldPriorityA = TicketPriority::factory()->create(['organization_id' => $organizationA->id]);
        $ticket = Ticket::factory()->create(['project_id' => $projectA->id, 'priority_id' => $oldPriorityA->id]);

        $organizationB = Organization::factory()->create();
        $priorityB = TicketPriority::factory()->create(['organization_id' => $organizationB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticket->id, $priorityB->id)
            ->assertForbidden();

        $this->assertSame($oldPriorityA->id, $ticket->fresh()->priority_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Idempotency: picking the priority the ticket already has must not
     * write and must not create a fake audit record — same discipline as
     * changeTicketStatus()'s own no-op guard.
     */
    public function test_change_ticket_priority_is_a_no_op_when_the_new_priority_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $priority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $priority->id]);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketPriority', $ticket->id, $priority->id)
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * priorityOptions() takes no parameters at all (see its own docblock
     * for why: TicketPriority is organization-scoped, not per-ticket), so
     * there is no client-suppliable identifier for a direct Livewire call
     * to abuse — this simply confirms the list it returns is scoped to the
     * session's own organization, proven via a real direct call.
     */
    public function test_priority_options_only_returns_priorities_for_the_current_organization(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $priorityA = TicketPriority::factory()->create(['organization_id' => $organizationA->id, 'name' => 'Organization A Priority']);

        $organizationB = Organization::factory()->create();
        TicketPriority::factory()->create(['organization_id' => $organizationB->id, 'name' => 'Organization B Priority']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        // Direct call proves it doesn't error and is callable with zero
        // arguments (no client-suppliable identifier exists to attack).
        $component = Livewire::test(OrganizationSupportView::class)
            ->call('priorityOptions')
            ->assertOk();

        // Content correctness, read directly from the same instance rather
        // than assumed from "no exception" — assertSee()/assertDontSee()
        // after a bare method call would only check whatever the page
        // happens to render elsewhere (e.g. per-ticket <select> options),
        // which is a weaker and more indirect proof than reading the
        // return value itself.
        $options = $component->instance()->priorityOptions();
        $this->assertSame([$priorityA->id], $options->pluck('id')->all());
        $this->assertSame(['Organization A Priority'], $options->pluck('name')->all());
    }

    // -------------------------------------------------------------------
    // Phase 6B of Support Action: changeTicketResponsible() / responsibleOptions()
    //
    // Audited from the actual codebase (App\Support\UserOptions::forProjectId(),
    // used by TicketResource/Forms/TicketForm.php's own responsible_id
    // select): who may become a ticket's responsible is really
    // $project->contributors (project owner + project_users) — project-
    // scoped, like TicketStatus, NOT organization-scoped like TicketPriority.
    // Support Action intersects that with organization membership on top
    // as an extra safety boundary — see resolveResponsibleOptions()'s own
    // docblock for why. So "valid responsible" here means: project
    // contributor AND organization member, both at once.
    // -------------------------------------------------------------------

    public function test_change_ticket_responsible_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $oldResponsible = User::factory()->create();
        $organization->users()->attach($oldResponsible->id, ['role' => 'member']);
        $project->users()->attach($oldResponsible->id, ['role' => 'employee']);

        $newResponsible = User::factory()->create();
        $organization->users()->attach($newResponsible->id, ['role' => 'member']);
        $project->users()->attach($newResponsible->id, ['role' => 'employee']);

        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => $oldResponsible->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, $newResponsible->id)
            ->assertSuccessful();

        $this->assertSame($newResponsible->id, $ticket->fresh()->responsible_id);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_responsible', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame($ticket->id, $action->target_id);
        $this->assertSame('responsible_id', $action->field);
        $this->assertSame((string) $oldResponsible->id, $action->old_value);
        $this->assertSame((string) $newResponsible->id, $action->new_value);
    }

    public function test_change_ticket_responsible_can_clear_the_assignment_with_null(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $oldResponsible = User::factory()->create();
        $organization->users()->attach($oldResponsible->id, ['role' => 'member']);
        $project->users()->attach($oldResponsible->id, ['role' => 'employee']);

        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => $oldResponsible->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, null)
            ->assertSuccessful();

        $this->assertNull($ticket->fresh()->responsible_id);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame((string) $oldResponsible->id, $action->old_value);
        $this->assertNull($action->new_value);
    }

    public function test_change_ticket_responsible_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $newResponsible = User::factory()->create();
        $organization->users()->attach($newResponsible->id, ['role' => 'member']);
        $project->users()->attach($newResponsible->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, $newResponsible->id)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_responsible_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
    }

    public function test_change_ticket_responsible_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
    }

    public function test_change_ticket_responsible_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
    }

    public function test_change_ticket_responsible_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
    }

    public function test_change_ticket_responsible_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
    }

    public function test_change_ticket_responsible_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $responsibleB = User::factory()->create();
        $organizationB->users()->attach($responsibleB->id, ['role' => 'member']);
        $projectB->users()->attach($responsibleB->id, ['role' => 'employee']);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'responsible_id' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticketB->id, $responsibleB->id)
            ->assertForbidden();

        $this->assertNull($ticketB->fresh()->responsible_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_responsible_rejects_a_nonexistent_ticket(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', 999999999, null)
            ->assertForbidden();

        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * A user from a completely different organization — not a member of
     * the session's organization at all, and not a contributor of the
     * ticket's project either.
     */
    public function test_change_ticket_responsible_rejects_a_user_from_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $otherOrganization = Organization::factory()->create();
        $userFromOtherOrg = User::factory()->create();
        $otherOrganization->users()->attach($userFromOtherOrg->id, ['role' => 'member']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, $userFromOtherOrg->id)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * The intersection this design specifically adds on top of the app's
     * real business rule (project contributors): a user who IS a
     * contributor of the ticket's project but is NOT a member of the
     * organization_users pivot for this organization must still be
     * rejected — project_users and organization_users are independent
     * axes in this app, so this is not a hypothetical.
     */
    public function test_change_ticket_responsible_rejects_a_project_contributor_who_is_not_an_organization_member(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $contributorOnly = User::factory()->create();
        $project->users()->attach($contributorOnly->id, ['role' => 'employee']);
        // Deliberately NOT attached to $organization->users().

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, $contributorOnly->id)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_responsible_rejects_a_nonexistent_user_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, 999999999)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->responsible_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_responsible_is_a_no_op_when_the_new_responsible_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $responsible = User::factory()->create();
        $organization->users()->attach($responsible->id, ['role' => 'member']);
        $project->users()->attach($responsible->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => $responsible->id]);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, $responsible->id)
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_responsible_is_a_no_op_when_already_unassigned_and_cleared_again(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketResponsible', $ticket->id, null)
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * responsibleOptions() only ever returns project contributors who are
     * ALSO organization members — proven with three distinct users: one
     * satisfying both (must appear), one belonging to a different
     * organization entirely (must not appear), and one who is a project
     * contributor but not an organization member (must not appear either).
     */
    public function test_responsible_options_only_returns_project_contributors_who_are_also_organization_members(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'responsible_id' => null]);

        $validCandidate = User::factory()->create(['name' => 'Valid Candidate']);
        $organization->users()->attach($validCandidate->id, ['role' => 'member']);
        $project->users()->attach($validCandidate->id, ['role' => 'employee']);

        $otherOrganization = Organization::factory()->create();
        $userFromOtherOrg = User::factory()->create(['name' => 'Other Organization User']);
        $otherOrganization->users()->attach($userFromOtherOrg->id, ['role' => 'member']);

        $contributorOnly = User::factory()->create(['name' => 'Contributor Without Membership']);
        $project->users()->attach($contributorOnly->id, ['role' => 'employee']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $component = Livewire::test(OrganizationSupportView::class)
            ->call('responsibleOptions', $ticket->id)
            ->assertOk();

        $options = $component->instance()->responsibleOptions($ticket->id);
        $this->assertSame([$validCandidate->id], $options->pluck('id')->all());
        $this->assertSame(['Valid Candidate'], $options->pluck('name')->all());
    }

    // -------------------------------------------------------------------
    // Phase 6C of Support Action: changeTicketDueDate()
    //
    // tickets.due_date is a nullable `date` column (migration
    // 2026_08_04_000000_add_due_date_to_tickets_table.php), cast to Carbon
    // on the Ticket model. TicketResource/Forms/TicketForm.php's own
    // DatePicker has no ->required() and no min/max/future-date
    // constraint — unlike status/priority/responsible, due_date has no
    // cross-referenced "valid options" scope to check, only "is this a
    // well-formed date or null".
    // -------------------------------------------------------------------

    public function test_change_ticket_due_date_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, '2026-02-15')
            ->assertSuccessful();

        $this->assertSame('2026-02-15', $ticket->fresh()->due_date->toDateString());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_due_date', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame($ticket->id, $action->target_id);
        $this->assertSame('due_date', $action->field);
        $this->assertSame('2026-01-01', $action->old_value);
        $this->assertSame('2026-02-15', $action->new_value);
    }

    public function test_change_ticket_due_date_can_clear_the_due_date_with_null(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, null)
            ->assertSuccessful();

        $this->assertNull($ticket->fresh()->due_date);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame('2026-01-01', $action->old_value);
        $this->assertNull($action->new_value);
    }

    public function test_change_ticket_due_date_can_set_a_due_date_from_null(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, '2026-03-10')
            ->assertSuccessful();

        $this->assertSame('2026-03-10', $ticket->fresh()->due_date->toDateString());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertNull($action->old_value);
        $this->assertSame('2026-03-10', $action->new_value);
    }

    public function test_change_ticket_due_date_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, '2026-02-15')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_due_date_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
    }

    public function test_change_ticket_due_date_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
    }

    public function test_change_ticket_due_date_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
    }

    public function test_change_ticket_due_date_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
    }

    public function test_change_ticket_due_date_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
    }

    public function test_change_ticket_due_date_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticketB->id, '2026-02-15')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $ticketB->fresh()->due_date->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_due_date_rejects_a_malformed_date_string(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, 'not-a-date')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Carbon::createFromFormat('Y-m-d', ...) alone can silently roll an
     * out-of-range date over into a different, valid-looking one instead
     * of failing — this proves parseDueDate()'s extra re-format-and-compare
     * step actually catches that rather than letting it slip through.
     */
    public function test_change_ticket_due_date_rejects_an_out_of_range_date_string(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, '2026-13-45')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $ticket->fresh()->due_date->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_due_date_is_a_no_op_when_the_new_due_date_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => '2026-01-01']);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, '2026-01-01')
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_due_date_is_a_no_op_when_already_null_and_cleared_again(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'due_date' => null]);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketDueDate', $ticket->id, null)
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // -------------------------------------------------------------------
    // Phase 6D of Support Action: changeTicketTitle()
    //
    // There is no "title" column at all in this schema — the field the
    // rest of the app calls "Ticket name" (TicketForm's own label, this
    // page's own Tickets table header) is `tickets.name`
    // (`$table->string('name')`, required + maxLength(255) on
    // TicketForm's TextInput). "Title" is purely the business-facing name
    // Phase 6D asked for this capability under; changeTicketTitle()
    // targets `name`, and the audit `field` column records 'name' (the
    // real column), not 'title'.
    // -------------------------------------------------------------------

    public function test_change_ticket_title_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, 'New title')
            ->assertSuccessful();

        $this->assertSame('New title', $ticket->fresh()->name);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_title', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame($ticket->id, $action->target_id);
        $this->assertSame('name', $action->field);
        $this->assertSame('Old title', $action->old_value);
        $this->assertSame('New title', $action->new_value);
    }

    public function test_change_ticket_title_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, 'New title')
            ->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_title_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
    }

    public function test_change_ticket_title_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
    }

    public function test_change_ticket_title_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
    }

    public function test_change_ticket_title_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
    }

    public function test_change_ticket_title_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
    }

    public function test_change_ticket_title_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'name' => 'Other org title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticketB->id, 'Hijacked title')
            ->assertForbidden();

        $this->assertSame('Other org title', $ticketB->fresh()->name);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_title_rejects_an_empty_title(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, '')
            ->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_title_rejects_a_whitespace_only_title(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, '   ')
            ->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_title_rejects_a_title_over_the_maximum_length(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Old title']);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, str_repeat('a', 256))
            ->assertForbidden();

        $this->assertSame('Old title', $ticket->fresh()->name);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_title_is_a_no_op_when_the_new_title_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Same title']);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketTitle', $ticket->id, 'Same title')
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // ------------------------------------------------- Phase 6E: labels

    public function test_change_ticket_labels_attaches_labels_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $labelA = Label::factory()->create(['organization_id' => $organization->id, 'name' => 'Bug']);
        $labelB = Label::factory()->create(['organization_id' => $organization->id, 'name' => 'Urgent']);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$labelA->id, $labelB->id])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$labelA->id, $labelB->id], $ticket->fresh()->labels->pluck('id')->all());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.change_labels', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame($ticket->id, $action->target_id);
        $this->assertSame('labels', $action->field);
        $this->assertSame('', $action->old_value);
        $this->assertSame(collect([$labelA->id, $labelB->id])->sort()->implode(','), $action->new_value);
    }

    public function test_change_ticket_labels_removes_labels(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $label = Label::factory()->create(['organization_id' => $organization->id]);
        $ticket->labels()->attach($label->id);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [])
            ->assertSuccessful();

        $this->assertCount(0, $ticket->fresh()->labels);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame((string) $label->id, $action->old_value);
        $this->assertSame('', $action->new_value);
    }

    public function test_change_ticket_labels_supports_multiple_labels_including_a_legacy_organizationless_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ownLabel = Label::factory()->create(['organization_id' => $organization->id]);
        $legacyLabel = Label::factory()->create(['organization_id' => null]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$ownLabel->id, $legacyLabel->id])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$ownLabel->id, $legacyLabel->id], $ticket->fresh()->labels->pluck('id')->all());
    }

    public function test_change_ticket_labels_ignores_duplicate_ids_without_a_spurious_audit_entry(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $label = Label::factory()->create(['organization_id' => $organization->id]);
        $ticket->labels()->attach($label->id);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$label->id, $label->id])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$label->id], $ticket->fresh()->labels->pluck('id')->all());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_rejects_an_invalid_label_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [999999])
            ->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_rejects_a_label_from_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $otherOrganization = Organization::factory()->create();
        $foreignLabel = Label::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$foreignLabel->id])
            ->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $label = Label::factory()->create(['organization_id' => $organizationA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticketB->id, [$label->id])
            ->assertForbidden();

        $this->assertCount(0, $ticketB->fresh()->labels);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $label = Label::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$label->id])
            ->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
    }

    public function test_change_ticket_labels_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
    }

    public function test_change_ticket_labels_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
    }

    public function test_change_ticket_labels_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
    }

    public function test_change_ticket_labels_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertCount(0, $ticket->fresh()->labels);
    }

    public function test_change_ticket_labels_is_a_no_op_when_the_new_set_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $label = Label::factory()->create(['organization_id' => $organization->id]);
        $ticket->labels()->attach($label->id);
        $updatedAt = $ticket->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$label->id])
            ->assertSuccessful();

        $this->assertTrue($ticket->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_ticket_labels_does_not_change_other_ticket_fields(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Untouched name']);
        $label = Label::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketLabels', $ticket->id, [$label->id])
            ->assertSuccessful();

        $fresh = $ticket->fresh();
        $this->assertSame('Untouched name', $fresh->name);
        $this->assertSame($ticket->status_id, $fresh->status_id);
        $this->assertSame($ticket->priority_id, $fresh->priority_id);
        $this->assertSame($ticket->responsible_id, $fresh->responsible_id);
    }

    // ------------------------------------------- Phase 6G: project status

    public function test_change_project_status_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $oldStatus = ProjectStatus::factory()->create(['organization_id' => $organization->id]);
        $newStatus = ProjectStatus::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeProjectStatus', $project->id, $newStatus->id)
            ->assertSuccessful();

        $this->assertSame($newStatus->id, $project->fresh()->status_id);

        $action = OrganizationSupportAction::where('target_id', $project->id)
            ->where('target_type', Project::class)
            ->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('project.change_status', $action->action);
        $this->assertSame('status_id', $action->field);
        $this->assertSame((string) $oldStatus->id, $action->old_value);
        $this->assertSame((string) $newStatus->id, $action->new_value);
    }

    public function test_change_project_status_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $status = ProjectStatus::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeProjectStatus', $project->id, $status->id)
            ->assertForbidden();

        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_project_status_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
    }

    public function test_change_project_status_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
    }

    public function test_change_project_status_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
    }

    public function test_change_project_status_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
    }

    public function test_change_project_status_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
    }

    public function test_change_project_status_rejects_a_status_from_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $originalStatusId = $project->status_id;

        $otherOrganization = Organization::factory()->create();
        $foreignStatus = ProjectStatus::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeProjectStatus', $project->id, $foreignStatus->id)
            ->assertForbidden();

        $this->assertSame($originalStatusId, $project->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_project_status_rejects_a_project_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $statusA = ProjectStatus::factory()->create(['organization_id' => $organizationA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $originalStatusId = $projectB->status_id;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeProjectStatus', $projectB->id, $statusA->id)
            ->assertForbidden();

        $this->assertSame($originalStatusId, $projectB->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_project_status_is_a_no_op_when_the_new_status_equals_the_current_one(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $status = ProjectStatus::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status_id' => $status->id]);
        $updatedAt = $project->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeProjectStatus', $project->id, $status->id)
            ->assertSuccessful();

        $this->assertTrue($project->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // ----------------------------------------------- Phase 6G: sprintStart

    public function test_sprint_start_succeeds_for_a_not_started_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $sprint->id)
            ->assertSuccessful();

        $fresh = $sprint->fresh();
        $this->assertNotNull($fresh->started_at);
        $this->assertNull($fresh->ended_at);

        $action = OrganizationSupportAction::where('target_id', $sprint->id)
            ->where('action', 'sprint.start')
            ->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame(Sprint::class, $action->target_type);
        $this->assertSame('started_at', $action->field);
    }

    public function test_sprint_start_atomically_closes_another_running_sprint_in_the_same_project(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $runningSprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $newSprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $newSprint->id)
            ->assertSuccessful();

        $this->assertNotNull($newSprint->fresh()->started_at);
        $this->assertNull($newSprint->fresh()->ended_at);
        $this->assertNotNull($runningSprint->fresh()->ended_at);

        $autoStopAction = OrganizationSupportAction::where('target_id', $runningSprint->id)
            ->where('action', 'sprint.auto_stop')
            ->firstOrFail();
        $this->assertSame('ended_at', $autoStopAction->field);

        $this->assertSame(2, OrganizationSupportAction::count());
    }

    public function test_sprint_start_does_not_affect_a_running_sprint_in_a_different_project(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $organization->id]);
        $runningInOtherProject = Sprint::factory()->started()->create(['project_id' => $projectB->id]);
        $newSprint = Sprint::factory()->create(['project_id' => $projectA->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $newSprint->id)
            ->assertSuccessful();

        $this->assertNotNull($runningInOtherProject->fresh()->started_at);
        $this->assertNull($runningInOtherProject->fresh()->ended_at);
        $this->assertSame(1, OrganizationSupportAction::count());
    }

    public function test_sprint_start_rejects_an_already_started_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $originalStartedAt = $sprint->started_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $sprint->id)
            ->assertForbidden();

        $this->assertTrue($sprint->fresh()->started_at->eq($originalStartedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_sprint_start_rejects_an_already_ended_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->ended()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $sprint->id)
            ->assertForbidden();

        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_sprint_start_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $sprint->id)
            ->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_sprint_start_rejects_a_sprint_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStart', $sprintB->id)
            ->assertForbidden();

        $this->assertNull($sprintB->fresh()->started_at);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // ------------------------------------------------ Phase 6G: sprintStop

    public function test_sprint_stop_succeeds_for_a_running_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStop', $sprint->id)
            ->assertSuccessful();

        $this->assertNotNull($sprint->fresh()->ended_at);

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame('sprint.stop', $action->action);
        $this->assertSame('ended_at', $action->field);
    }

    public function test_sprint_stop_rejects_a_not_yet_started_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStop', $sprint->id)
            ->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_sprint_stop_rejects_an_already_ended_sprint(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->ended()->create(['project_id' => $project->id]);
        $originalEndedAt = $sprint->ended_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStop', $sprint->id)
            ->assertForbidden();

        $this->assertTrue($sprint->fresh()->ended_at->eq($originalEndedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_sprint_stop_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStop', $sprint->id)
            ->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_sprint_stop_rejects_a_sprint_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->started()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('sprintStop', $sprintB->id)
            ->assertForbidden();

        $this->assertNull($sprintB->fresh()->ended_at);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // --------------------------------------- Phase 6G: changeSprintDates

    public function test_change_sprint_dates_succeeds_with_an_active_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, '2026-02-01', '2026-02-14')
            ->assertSuccessful();

        $fresh = $sprint->fresh();
        $this->assertSame('2026-02-01', $fresh->starts_at->toDateString());
        $this->assertSame('2026-02-14', $fresh->ends_at->toDateString());

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame('sprint.change_dates', $action->action);
        $this->assertSame('starts_at,ends_at', $action->field);
        $this->assertSame('2026-01-01,2026-01-07', $action->old_value);
        $this->assertSame('2026-02-01,2026-02-14', $action->new_value);
    }

    public function test_change_sprint_dates_propagates_to_the_mirrored_epic(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, '2026-03-01', '2026-03-10')
            ->assertSuccessful();

        $epic = $sprint->fresh()->epic;
        $this->assertNotNull($epic);
        $this->assertSame('2026-03-01', $epic->starts_at->toDateString());
        $this->assertSame('2026-03-10', $epic->ends_at->toDateString());
    }

    public function test_change_sprint_dates_rejects_a_malformed_date(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, 'not-a-date', '2026-01-07')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_sprint_dates_rejects_a_start_date_after_the_end_date(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, '2026-02-14', '2026-02-01')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_sprint_dates_is_a_no_op_when_the_new_dates_equal_the_current_ones(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);
        $updatedAt = $sprint->updated_at;

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, '2026-01-01', '2026-01-07')
            ->assertSuccessful();

        $this->assertTrue($sprint->fresh()->updated_at->eq($updatedAt));
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_change_sprint_dates_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprint->id, '2026-02-01', '2026-02-14')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_is_denied_for_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_is_denied_for_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_is_denied_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->actingAs($adminB);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame('2026-01-01', $sprint->fresh()->starts_at->toDateString());
    }

    public function test_change_sprint_dates_rejects_a_sprint_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create([
            'project_id' => $projectB->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-07',
        ]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('changeSprintDates', $sprintB->id, '2026-02-01', '2026-02-14')
            ->assertForbidden();

        $this->assertSame('2026-01-01', $sprintB->fresh()->starts_at->toDateString());
        $this->assertSame(0, OrganizationSupportAction::count());
    }
}

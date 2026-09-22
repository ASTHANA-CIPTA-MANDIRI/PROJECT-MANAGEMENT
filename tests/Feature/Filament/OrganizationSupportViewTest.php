<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Models\Epic;
use App\Models\Label;
use App\Models\Organization;
use App\Models\OrganizationSupportAccessGrant;
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

    /**
     * Phase 9: mirrors SupportSessionContextTest::owner()/activeFullAccessSession()
     * exactly — reused here rather than reimplemented so both test classes'
     * Full Access grant lifecycle setup stays defined the same way.
     */
    private function owner(Organization $organization): User
    {
        $owner = User::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        return $owner;
    }

    private function activeFullAccessSession(User $admin, Organization $organization): OrganizationSupportSession
    {
        $owner = $this->owner($organization);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        return SupportSessionContext::consumeFullAccessGrant($admin, $grant);
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

    // -------------------------------------------------------------------
    // Regression coverage for the Full Access view bug found in manual
    // browser testing: authorizeCapability() already allowed a Full Access
    // session to call changeTicketStatus() (Phase 9), but the Blade view's
    // support-level label, the now-removed hard-coded "Read Only" span,
    // and the ticket-status control itself were all still gated purely on
    // isSupportAction() — a Full Access session was shown as fully
    // read-only, with no control to actually use the capability it was
    // authorized for. The existing Phase 9 tests above (e.g.
    // test_change_ticket_status_succeeds_with_an_active_full_access_session)
    // call changeTicketStatus() directly through Livewire::call(), which
    // proves the backend is correct but never renders/inspects the HTML,
    // so they could not have caught this.
    // -------------------------------------------------------------------

    /**
     * The bug itself: a Full Access session must render the ticket status
     * <select> (the control that submits to changeTicketStatus()), not
     * just a read-only status badge.
     */
    public function test_a_full_access_session_renders_the_ticket_status_control(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $status = TicketStatus::factory()->default()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $status->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee('$wire.changeTicketStatus('.$ticket->id, false);
    }

    /**
     * The support-level label must correctly identify a Full Access
     * session as "Full Access" — not fall through the old binary
     * isSupportAction() ternary into "Read Only".
     */
    public function test_a_full_access_session_shows_the_full_access_support_level_label(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee(__('Support level: Full Access'))
            ->assertDontSee(__('Support level: Read Only'))
            ->assertDontSee(__('Support level: Support Action'));
    }

    /**
     * The fix must stay scoped to exactly the one capability Full Access
     * actually holds (SupportSessionContext::FULL_ACCESS_CAPABILITIES is
     * only ['change_ticket_status']) — a Full Access session must not see
     * controls for any other Support-Action-only field.
     */
    public function test_a_full_access_session_does_not_render_controls_for_capabilities_outside_the_allowlist(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $priority = TicketPriority::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'priority_id' => $priority->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertDontSee('$wire.changeTicketPriority(', false)
            ->assertDontSee('$wire.changeTicketResponsible(', false)
            ->assertDontSee('$wire.changeTicketDueDate(', false)
            ->assertDontSee('$wire.changeTicketTitle(', false)
            ->assertDontSee('$wire.changeTicketLabels(', false)
            ->assertDontSee('$wire.changeProjectStatus(', false)
            ->assertDontSee('$wire.sprintStart(', false)
            ->assertDontSee('$wire.sprintStop(', false)
            ->assertDontSee('$wire.changeSprintDates(', false);
    }

    /**
     * Regression: a Support Action session must keep seeing every control
     * it already had, including the ticket status select — the fix widens
     * that one block's condition with an OR, it must not narrow it.
     */
    public function test_a_support_action_session_still_renders_all_of_its_existing_controls(): void
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
            ->assertSuccessful()
            ->assertSee(__('Support level: Support Action'))
            ->assertSee('$wire.changeTicketStatus('.$ticket->id, false)
            ->assertSee('$wire.changeTicketPriority('.$ticket->id, false)
            ->assertSee('$wire.changeTicketResponsible('.$ticket->id, false)
            ->assertSee('$wire.changeTicketDueDate('.$ticket->id, false)
            ->assertSee('$wire.changeTicketTitle('.$ticket->id, false)
            ->assertSee('$wire.changeTicketLabels('.$ticket->id, false);
    }

    /**
     * Regression: a plain Read Only session must still render no write
     * controls at all, and its label must stay "Read Only".
     */
    public function test_a_read_only_session_still_renders_no_controls(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee(__('Support level: Read Only'))
            ->assertDontSee(__('Support level: Support Action'))
            ->assertDontSee(__('Support level: Full Access'))
            ->assertDontSee('$wire.changeTicketStatus('.$ticket->id, false)
            ->assertDontSee('$wire.changeTicketPriority('.$ticket->id, false)
            ->assertDontSee('$wire.changeTicketResponsible('.$ticket->id, false)
            ->assertDontSee('$wire.changeTicketDueDate('.$ticket->id, false)
            ->assertDontSee('$wire.changeTicketTitle('.$ticket->id, false)
            ->assertDontSee('$wire.changeTicketLabels('.$ticket->id, false);
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
    // Phase 9: changeTicketStatus() through a Full Access session — the
    // pilot capability proving Full Access is a real superset of Support
    // Action for exactly this one action, and only this one. See
    // App\Support\SupportSessionContext::authorizeCapability()'s own
    // docblock for the full case-by-case reasoning this section exercises
    // end to end through the real Livewire method.
    // -------------------------------------------------------------------

    /**
     * Positive case #1/#2: a genuinely valid, consumed, un-revoked Full
     * Access grant for the ticket's own organization must be allowed to
     * change the ticket's status, and the resulting audit row must record
     * the same fields a Support Action change already does — including
     * actor_user_id being the real authenticated Super Admin (server-
     * derived from auth()->id(), never a Livewire-supplied value — there
     * is no such parameter on changeTicketStatus() to begin with).
     */
    public function test_change_ticket_status_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->default()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertSuccessful();

        $this->assertSame($newStatus->id, $ticket->fresh()->status_id);

        // TicketObserver's existing side effect must still fire — this
        // action must go through the ordinary Eloquent save(), never
        // bypass it, exactly like the Support Action path above.
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

    /**
     * Negative case #7: a Full Access session for Organization A must not
     * be able to reach Organization B's ticket at all, proven end to end
     * through the real changeTicketStatus() Livewire method — not just
     * through authorizeCapability() in isolation. No mutation, no audit
     * row, for either organization's data.
     */
    public function test_change_ticket_status_via_full_access_rejects_a_ticket_from_a_different_organization(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->customStatuses()->create(['organization_id' => $organizationB->id]);
        $oldStatusB = TicketStatus::factory()->forProject($projectB)->create();
        $newStatusB = TicketStatus::factory()->forProject($projectB)->create();
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'status_id' => $oldStatusB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticketB->id, $newStatusB->id)
            ->assertForbidden();

        $this->assertSame($oldStatusB->id, $ticketB->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case #3: a Full Access session with no grant at all behind
     * it (raw DB tampering / a future bug skipping consumeFullAccessGrant())
     * must be denied, exactly like authorizeCapability()'s own unit test —
     * proven here through the real Livewire method.
     */
    public function test_change_ticket_status_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 9 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case #4: an APPROVED-but-not-yet-CONSUMED grant must not
     * let changeTicketStatus() through even if a full_access session row
     * somehow already exists referencing it.
     */
    public function test_change_ticket_status_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 9 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case #5: an expired Full Access session must already fail
     * userCanAccessPage() at mount, exactly like the Support Action
     * expiry case above — the page itself is forbidden before any
     * action-specific check would run.
     */
    public function test_change_ticket_status_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
    }

    /**
     * Phase 10 — Race & Rollback Proof: Full Access's mirror of
     * test_change_ticket_status_is_denied_for_an_ended_support_action_session
     * above. SupportSessionContext::stop() sets ended_at generically —
     * userCanAccessPage() never distinguishes by level when deciding
     * whether an active session still exists — so a Full Access session
     * ended mid-lifecycle must lock the whole page out at mount, exactly
     * like the Support Action case, before changeTicketStatus()'s own
     * method-level authorizeFullAccess() check would ever run.
     */
    public function test_change_ticket_status_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        // The session is already ended by the time this page is mounted —
        // same reasoning as the Support Action case above.
        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case #6: the grant behind an otherwise-valid, currently
     * active Full Access session is revoked mid-session (an Owner
     * revoking access) — changeTicketStatus() must deny even though the
     * session row itself was never touched.
     */
    public function test_change_ticket_status_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertForbidden();

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // -------------------------------------------------------------------
    // Phase 10 — Race & Rollback Proof: changeTicketStatus() wraps
    // $ticket->save() and OrganizationSupportAction::create() in one
    // DB::transaction() closure (see that method's own body). The two
    // tests below prove — empirically, against the real SQLite test
    // connection, not by reading the source — that the wrapping is real:
    // a forced failure partway through must roll back every write made
    // inside the closure, including one that already ran and returned
    // before the failure happened. Neither test touches production code;
    // both use a test-only Eloquent model event listener that is flushed
    // in a finally block, the same technique already established by
    // SupportSessionContextTest::test_consume_full_access_grant_rolls_back_the_grant_when_session_creation_fails
    // (Phase 6).
    // -------------------------------------------------------------------

    /**
     * Failure point #1: *between* the two writes, not inside either of
     * them — Ticket::updated() fires after $ticket->save()'s UPDATE
     * query has already executed (and after TicketObserver::updating()
     * has already created a TicketActivity row for this same change),
     * but before OrganizationSupportAction::create() is even called. If
     * the two writes were not truly inside one transaction, both the
     * status column and the TicketActivity row would already be durably
     * saved by the time this forced exception propagates — proving
     * atomicity requires showing they are not.
     */
    public function test_change_ticket_status_rolls_back_the_ticket_mutation_when_a_failure_happens_between_the_two_writes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->default()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::updated(function () {
            throw new \RuntimeException('Forced failure for Phase 10 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('changeTicketStatus', $ticket->id, $newStatus->id);
                $this->fail('Expected the forced between-writes exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 10 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        // $ticket->save() already ran its UPDATE query, and
        // TicketObserver::updating() already created a TicketActivity row,
        // both before this forced failure — both must still be rolled
        // back by DB::transaction(), or this assertion would see the new
        // status/activity survive the exception.
        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());
    }

    /**
     * Failure point #2: inside OrganizationSupportAction::create() itself
     * — the last statement in the transaction, and the specific "audit
     * insert fails" scenario. $ticket->save() (the *first* statement)
     * has already fully executed by this point; this proves that a
     * failure confined entirely to the audit write still reaches back
     * and rolls back the earlier, unrelated ticket mutation too.
     */
    public function test_change_ticket_status_rolls_back_the_ticket_mutation_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->customStatuses()->create(['organization_id' => $organization->id]);
        $oldStatus = TicketStatus::factory()->default()->forProject($project)->create();
        $newStatus = TicketStatus::factory()->forProject($project)->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $oldStatus->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 10 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('changeTicketStatus', $ticket->id, $newStatus->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 10 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertSame($oldStatus->id, $ticket->fresh()->status_id);
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());

        // The session/grant itself is untouched by the failed attempt —
        // a retry against the same still-active Full Access session must
        // still succeed, exactly like Phase 6's "the Grant is still
        // usable afterward" check.
        Livewire::test(OrganizationSupportView::class)
            ->call('changeTicketStatus', $ticket->id, $newStatus->id)
            ->assertSuccessful();

        $this->assertSame($newStatus->id, $ticket->fresh()->status_id);

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
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

    // -------------------------------------------------------------------
    // Phase 11 — deleteTicket()/restoreTicket(): the first Destructive
    // Category A capability. Unlike every write method above,
    // these two are deliberately Full-Access-only — a Support Action
    // session must be refused exactly like a Read Only one. See
    // deleteTicket()'s own docblock for why they bypass
    // SupportSessionContext::authorizeCapability() entirely and call
    // isFullAccessCapabilityAllowed()/authorizeFullAccess() directly
    // instead — this section's
    // test_delete_ticket_is_denied_for_a_support_action_session and
    // test_restore_ticket_is_denied_for_a_support_action_session are the
    // empirical proof that choice actually closes the hole a naive
    // authorizeCapability('delete_ticket') call would have left open
    // (Support Action sessions are far more common than an Owner-approved
    // Full Access grant).
    // -------------------------------------------------------------------

    /**
     * Positive case: a genuinely valid, consumed, un-revoked Full Access
     * grant for the ticket's own organization must be allowed to soft-
     * delete it — the mutation, the audit row (all 8 non-id columns), and
     * TicketObserver::deleted()'s existing cache-invalidation side effect
     * (implicitly exercised, not directly assertable) all in one atomic
     * write.
     */
    public function test_delete_ticket_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertSuccessful();

        $trashed = Ticket::withTrashed()->find($ticket->id);
        $this->assertTrue($trashed->trashed());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.delete', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame('deleted_at', $action->field);
        $this->assertNull($action->old_value);
        $this->assertSame((string) $trashed->deleted_at, $action->new_value);
    }

    /**
     * Symmetric positive case for restoreTicket(): a Full Access session
     * may undo a soft delete, restoring the ticket and recording the
     * inverse audit row (old_value the previous deleted_at, new_value
     * null).
     */
    public function test_restore_ticket_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();
        $oldDeletedAt = (string) Ticket::withTrashed()->find($ticket->id)->deleted_at;

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertSuccessful();

        $this->assertFalse($ticket->fresh()->trashed());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('ticket.restore', $action->action);
        $this->assertSame(Ticket::class, $action->target_type);
        $this->assertSame('deleted_at', $action->field);
        $this->assertSame($oldDeletedAt, $action->old_value);
        $this->assertNull($action->new_value);
    }

    /**
     * The critical proof for this phase's Opsi A resolution: a Support
     * Action session — far more commonly available than an Owner-approved
     * Full Access grant — must not be able to reach deleteTicket() at all.
     * If this method called SupportSessionContext::authorizeCapability()
     * the way changeTicketStatus() does, this test would fail (that
     * method's Support Action branch returns before the capability
     * allowlist is ever consulted — see
     * SupportSessionContextTest::test_authorize_capability_allows_a_support_action_session_for_any_capability).
     * deleteTicket() instead calls authorizeFullAccess() directly, which
     * only ever accepts session->isFullAccess() === true.
     */
    public function test_delete_ticket_is_denied_for_a_support_action_session(): void
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
            ->call('deleteTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Symmetric proof for restoreTicket() — same reasoning as
     * test_delete_ticket_is_denied_for_a_support_action_session above.
     */
    public function test_restore_ticket_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_ticket_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_ticket_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_restore_ticket_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_delete_ticket_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_restore_ticket_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_delete_ticket_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_restore_ticket_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_delete_ticket_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: a Full Access session with no grant at all behind it
     * (raw DB tampering / a future bug skipping consumeFullAccessGrant())
     * must be denied — mirrors
     * test_change_ticket_status_is_denied_for_a_full_access_session_with_no_grant.
     */
    public function test_delete_ticket_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 11 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 11 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: an APPROVED-but-not-yet-CONSUMED grant must not let
     * deleteTicket() through even if a full_access session row somehow
     * already exists referencing it.
     */
    public function test_delete_ticket_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 11 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 11 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: the grant behind an otherwise-valid, currently active
     * Full Access session is revoked mid-session (an Owner revoking
     * access) — deleteTicket() must deny even though the session row
     * itself was never touched.
     */
    public function test_delete_ticket_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * The core organization-boundary test: a Full Access session for
     * Organization A must not be able to reach Organization B's ticket at
     * all — not "reach it but get refused after loading", but never find
     * it in the first place.
     */
    public function test_delete_ticket_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticketB->id)
            ->assertForbidden();

        $this->assertFalse($ticketB->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_ticket_rejects_a_ticket_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);
        $ticketB->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticketB->id)
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticketB->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * restoreTicket()'s own precondition: a ticket that is not currently
     * trashed is an invalid target. deleteTicket() has no equivalent test
     * (see that method's own comment — its query already excludes a
     * trashed ticket via the default SoftDeletingScope, so there is
     * nothing distinct to prove beyond the "ticket not found" case already
     * covered by the cross-organization test above).
     */
    public function test_restore_ticket_rejects_a_ticket_that_is_not_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * "Capability not in the allowlist" (one of STEP 11's negative
     * scenarios) has no Livewire-reachable equivalent for deleteTicket()/
     * restoreTicket(): unlike changeTicketStatus() (reached through
     * authorizeCapability(), which takes a capability string derived from
     * which method was called, not a client-suppliable parameter either),
     * these two methods pass their own capability as a hardcoded literal
     * to isFullAccessCapabilityAllowed() — there is no value on the wire
     * to substitute a disallowed capability with. The guard itself is
     * proven directly at the SupportSessionContext level instead (see
     * SupportSessionContextTest::test_full_access_capability_allowlist_allows_change_ticket_status_and_the_phase_11_ticket_capabilities
     * and
     * test_full_access_capability_allowlist_denies_every_capability_because_none_is_approved_yet).
     * This test instead proves the Phase 11 wiring did not silently forget
     * to add either literal to FULL_ACCESS_CAPABILITIES, which would
     * otherwise make both methods permanently unreachable even for a fully
     * valid Full Access session.
     */
    public function test_delete_and_restore_ticket_capabilities_are_present_in_the_full_access_allowlist(): void
    {
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_ticket'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_ticket'));
    }

    // -------------------------------------------------------------------
    // Phase 11 — Race & Rollback Proof: same technique as Phase 10's
    // changeTicketStatus() tests (SupportSessionContextTest::
    // test_consume_full_access_grant_rolls_back_the_grant_when_session_creation_fails
    // originated it) — a test-only Eloquent model event listener forces a
    // failure at a precise point, flushed in a finally block, proving
    // DB::transaction() really rolls back both writes together rather than
    // leaving the first one durably committed.
    // -------------------------------------------------------------------

    /**
     * Failure point: *after* $ticket->delete() has already run its UPDATE
     * query (Ticket::deleted() fires once that completes) but *before*
     * OrganizationSupportAction::create() is ever called. If the two
     * writes were not truly inside one transaction, the ticket would
     * already be durably trashed by the time this forced exception
     * propagates.
     */
    public function test_delete_ticket_rolls_back_the_ticket_mutation_when_a_failure_happens_between_the_two_writes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::deleted(function () {
            throw new \RuntimeException('Forced failure for Phase 11 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteTicket', $ticket->id);
                $this->fail('Expected the forced between-writes exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 11 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());
    }

    /**
     * Symmetric failure point for restoreTicket(): forced on
     * Ticket::restored(), which fires after $ticket->restore()'s UPDATE
     * query has already run but before the audit row is created.
     */
    public function test_restore_ticket_rolls_back_the_ticket_mutation_when_a_failure_happens_between_the_two_writes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::restored(function () {
            throw new \RuntimeException('Forced failure for Phase 11 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreTicket', $ticket->id);
                $this->fail('Expected the forced between-writes exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 11 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());
    }

    /**
     * Failure point #2: inside OrganizationSupportAction::create() itself
     * — the last statement in the transaction. $ticket->delete() (the
     * *first* statement) has already fully executed by this point; this
     * proves a failure confined entirely to the audit write still reaches
     * back and rolls back the earlier, unrelated ticket mutation too. A
     * retry against the same still-active session must still succeed
     * afterward.
     */
    public function test_delete_ticket_rolls_back_the_ticket_mutation_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 11 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteTicket', $ticket->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 11 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteTicket', $ticket->id)
            ->assertSuccessful();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    /**
     * Symmetric audit-insert-fails rollback proof for restoreTicket().
     */
    public function test_restore_ticket_rolls_back_the_ticket_mutation_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 11 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreTicket', $ticket->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 11 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $ticket->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreTicket', $ticket->id)
            ->assertSuccessful();

        $this->assertFalse($ticket->fresh()->trashed());

        $action = OrganizationSupportAction::where('target_id', $ticket->id)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    // -------------------------------------------------------------------
    // Phase 12 — deleteSprint()/restoreSprint(): Destructive Category B,
    // following deleteTicket()/restoreTicket()'s exact Full-Access-only
    // shape (Phase 11). The one structural difference this section proves
    // out: App\Observers\SprintObserver has no deleting()/deleted() hook at
    // all (so deleteSprint() never touches the sprint's mirrored epic), but
    // its restoring() hook unconditionally cascade-restores an already-
    // trashed mirrored epic — a real side effect restoreSprint() has to
    // audit as its own separate row and roll back together with the sprint
    // mutation.
    // -------------------------------------------------------------------

    /**
     * Positive case: a genuinely valid, consumed, un-revoked Full Access
     * grant for the sprint's own organization must be allowed to soft-
     * delete it — the mutation and the audit row, in one atomic write.
     */
    public function test_delete_sprint_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertSuccessful();

        $trashed = Sprint::withTrashed()->find($sprint->id);
        $this->assertTrue($trashed->trashed());

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('sprint.delete', $action->action);
        $this->assertSame(Sprint::class, $action->target_type);
        $this->assertSame('deleted_at', $action->field);
        $this->assertNull($action->old_value);
        $this->assertSame((string) $trashed->deleted_at, $action->new_value);
    }

    /**
     * The core Phase 12 inspection finding, empirically proven: deleting a
     * sprint must never touch its mirrored epic — App\Observers\SprintObserver
     * has no deleting()/deleted() hook, unlike its restoring() hook below.
     */
    public function test_delete_sprint_does_not_touch_the_mirrored_epic(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epicId = $sprint->epic_id;

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertSuccessful();

        $epic = Epic::withTrashed()->findOrFail($epicId);
        $this->assertFalse($epic->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_type', Epic::class)->count());
    }

    /**
     * Symmetric positive case for restoreSprint(), with no epic cascade in
     * play (the epic was never independently trashed) — a single audit row.
     */
    public function test_restore_sprint_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();
        $oldDeletedAt = (string) Sprint::withTrashed()->find($sprint->id)->deleted_at;

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertSuccessful();

        $this->assertFalse($sprint->fresh()->trashed());

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
        $this->assertSame($admin->id, $action->actor_user_id);
        $this->assertSame($organization->id, $action->organization_id);
        $this->assertSame('sprint.restore', $action->action);
        $this->assertSame(Sprint::class, $action->target_type);
        $this->assertSame('deleted_at', $action->field);
        $this->assertSame($oldDeletedAt, $action->old_value);
        $this->assertNull($action->new_value);

        // No epic side effect fired: nothing to audit on Epic.
        $this->assertSame(0, OrganizationSupportAction::where('target_type', Epic::class)->count());
    }

    /**
     * The Phase 12 cascade proof: an epic independently trashed beforehand
     * (e.g. through App\Http\Livewire\RoadMap\EpicForm::delete(), which lets
     * any project member soft-delete any epic, mirror or not — confirmed
     * during Phase 12 inspection) must be cascade-restored by
     * App\Observers\SprintObserver::restoring() when its sprint is restored
     * through this page, and that cascade must be audited as its own
     * separate 'epic.auto_restore' row — mirroring how sprintStart()
     * already audits the *other* sprint it auto-stops as a distinct row.
     */
    public function test_restore_sprint_also_restores_a_separately_trashed_mirrored_epic_and_audits_it_as_a_separate_row(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;

        // Independently trash the mirrored epic first (simulating a Road Map
        // delete), then trash the sprint itself — the two are unrelated
        // writes, exactly as they would be in production.
        $epic->delete();
        $sprint->delete();
        $oldSprintDeletedAt = (string) Sprint::withTrashed()->find($sprint->id)->deleted_at;
        $oldEpicDeletedAt = (string) Epic::withTrashed()->find($epic->id)->deleted_at;

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertSuccessful();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertFalse($epic->fresh()->trashed());

        $sprintAction = OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->firstOrFail();
        $this->assertSame('sprint.restore', $sprintAction->action);
        $this->assertSame($oldSprintDeletedAt, $sprintAction->old_value);
        $this->assertNull($sprintAction->new_value);

        $epicAction = OrganizationSupportAction::where('target_id', $epic->id)->where('target_type', Epic::class)->firstOrFail();
        $this->assertSame($session->id, $epicAction->support_session_id);
        $this->assertSame($admin->id, $epicAction->actor_user_id);
        $this->assertSame($organization->id, $epicAction->organization_id);
        $this->assertSame('epic.auto_restore', $epicAction->action);
        $this->assertSame(Epic::class, $epicAction->target_type);
        $this->assertSame('deleted_at', $epicAction->field);
        $this->assertSame($oldEpicDeletedAt, $epicAction->old_value);
        $this->assertNull($epicAction->new_value);
    }

    /**
     * The critical proof for this phase's Opsi A resolution, same reasoning
     * as test_delete_ticket_is_denied_for_a_support_action_session: a
     * Support Action session — which already legitimately has
     * sprintStart()/sprintStop()/changeSprintDates() as its own capabilities
     * — must not be able to reach deleteSprint() at all.
     */
    public function test_delete_sprint_is_denied_for_a_support_action_session(): void
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
            ->call('deleteSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Symmetric proof for restoreSprint() — same reasoning as
     * test_delete_sprint_is_denied_for_a_support_action_session above.
     */
    public function test_restore_sprint_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_sprint_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_sprint_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_restore_sprint_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_delete_sprint_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_restore_sprint_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_delete_sprint_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_restore_sprint_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_delete_sprint_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: a Full Access session with no grant at all behind it
     * (raw DB tampering / a future bug skipping consumeFullAccessGrant())
     * must be denied — mirrors the deleteTicket() equivalent.
     */
    public function test_delete_sprint_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 12 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 12 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: an APPROVED-but-not-yet-CONSUMED grant must not let
     * deleteSprint() through even if a full_access session row somehow
     * already exists referencing it.
     */
    public function test_delete_sprint_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 12 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 12 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: the grant behind an otherwise-valid, currently active
     * Full Access session is revoked mid-session (an Owner revoking
     * access) — deleteSprint() must deny even though the session row
     * itself was never touched.
     */
    public function test_delete_sprint_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * The core organization-boundary test: a Full Access session for
     * Organization A must not be able to reach Organization B's sprint at
     * all — not "reach it but get refused after loading", but never find
     * it in the first place.
     */
    public function test_delete_sprint_rejects_a_sprint_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprintB->id)
            ->assertForbidden();

        $this->assertFalse($sprintB->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_sprint_rejects_a_sprint_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);
        $sprintB->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprintB->id)
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprintB->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * restoreSprint()'s own precondition: a sprint that is not currently
     * trashed is an invalid target. deleteSprint() has no equivalent test
     * (see that method's own comment — its query already excludes a
     * trashed sprint via the default SoftDeletingScope, so there is
     * nothing distinct to prove beyond the "sprint not found" case already
     * covered by the cross-organization test above).
     */
    public function test_restore_sprint_rejects_a_sprint_that_is_not_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * "Capability not in the allowlist" has no Livewire-reachable equivalent
     * for deleteSprint()/restoreSprint(), for the same reason documented on
     * test_delete_and_restore_ticket_capabilities_are_present_in_the_full_access_allowlist —
     * this proves the Phase 12 wiring did not silently forget to add either
     * literal to FULL_ACCESS_CAPABILITIES.
     */
    public function test_delete_and_restore_sprint_capabilities_are_present_in_the_full_access_allowlist(): void
    {
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_sprint'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_sprint'));
    }

    // -------------------------------------------------------------------
    // Phase 12 — Race & Rollback Proof: same technique as Phase 11's
    // deleteTicket()/restoreTicket() rollback tests.
    // -------------------------------------------------------------------

    /**
     * Failure point: *after* $sprint->delete() has already run its UPDATE
     * query but *before* OrganizationSupportAction::create() is ever
     * called.
     */
    public function test_delete_sprint_rolls_back_the_sprint_mutation_when_a_failure_happens_between_the_two_writes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Sprint::deleted(function () {
            throw new \RuntimeException('Forced failure for Phase 12 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteSprint', $sprint->id);
                $this->fail('Expected the forced between-writes exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 12 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Sprint::flushEventListeners();
        }

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $sprint->id)->count());
    }

    /**
     * Symmetric failure point for restoreSprint(): forced on
     * Sprint::restored(), which fires after $sprint->restore()'s UPDATE
     * query (and SprintObserver::restoring()'s own epic cascade, which
     * fires even earlier) has already run but before the audit row is
     * created. Proves the epic cascade rolls back together with the sprint
     * mutation, not just the sprint's own deleted_at.
     */
    public function test_restore_sprint_rolls_back_the_sprint_mutation_and_the_epic_cascade_when_a_failure_happens_between_the_two_writes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;
        $epic->delete();
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Sprint::restored(function () {
            throw new \RuntimeException('Forced failure for Phase 12 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreSprint', $sprint->id);
                $this->fail('Expected the forced between-writes exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 12 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Sprint::flushEventListeners();
        }

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($epic->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->count());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $epic->id)->where('target_type', Epic::class)->count());
    }

    /**
     * Failure point #2: inside OrganizationSupportAction::create() itself
     * — the last statement in the transaction. $sprint->delete() (the
     * *first* statement) has already fully executed by this point; this
     * proves a failure confined entirely to the audit write still reaches
     * back and rolls back the earlier, unrelated sprint mutation too. A
     * retry against the same still-active session must still succeed
     * afterward.
     */
    public function test_delete_sprint_rolls_back_the_sprint_mutation_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 12 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteSprint', $sprint->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 12 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $sprint->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteSprint', $sprint->id)
            ->assertSuccessful();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    /**
     * Symmetric audit-insert-fails rollback proof for restoreSprint(),
     * without the epic cascade in play (no trashed epic to restore) — the
     * sprint mutation itself must still roll back.
     */
    public function test_restore_sprint_rolls_back_the_sprint_mutation_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 12 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreSprint', $sprint->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 12 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $sprint->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreSprint', $sprint->id)
            ->assertSuccessful();

        $this->assertFalse($sprint->fresh()->trashed());

        $action = OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    /**
     * Symmetric audit-insert-fails rollback proof, this time WITH the epic
     * cascade in play: OrganizationSupportAction::creating() is forced to
     * fail on its first-ever call, which is the sprint.restore row — the
     * epic.auto_restore row is never even attempted, and both the sprint
     * mutation and the epic cascade restore() that already ran inside the
     * same transaction must roll back together.
     */
    public function test_restore_sprint_rolls_back_the_epic_cascade_together_with_the_sprint_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;
        $epic->delete();
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 12 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreSprint', $sprint->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 12 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($epic->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $sprint->id)->where('target_type', Sprint::class)->count());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $epic->id)->where('target_type', Epic::class)->count());
    }

    // -------------------------------------------------------------------
    // Phase 13 — deleteProject()/restoreProject(): the highest-blast-radius
    // Full Access capability yet, following deleteTicket()/deleteSprint()'s
    // exact Full-Access-only shape (Phase 11/12), but a Project cascades to
    // every Ticket, Sprint and Epic beneath it via
    // App\Observers\ProjectObserver::deleting()/restoring(). Two structural
    // differences this section proves out: (1) the cascade is reused, not
    // reimplemented — deleteProject()/restoreProject() call plain
    // $project->delete()/restore() and nothing more, so this section proves
    // the cascade actually happens rather than assuming it; (2) the audit
    // trail is one bounded 'project.delete_cascade_summary'/
    // 'project.restore_cascade_summary' row (counts only), not one row per
    // cascaded child — see deleteProject()'s own docblock for why.
    // -------------------------------------------------------------------

    /**
     * Positive case: a genuinely valid, consumed, un-revoked Full Access
     * grant must be allowed to soft-delete the project, its own audit row
     * matching the exact old/new shape deleteTicket()/deleteSprint() use,
     * plus the bounded cascade-summary row recording the real ticket/
     * sprint/epic counts that were cascaded.
     */
    public function test_delete_project_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Ticket::factory()->count(2)->create(['project_id' => $project->id]);
        Sprint::factory()->create(['project_id' => $project->id]);
        Epic::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertSuccessful();

        $trashed = Project::withTrashed()->find($project->id);
        $this->assertTrue($trashed->trashed());

        $deleteAction = OrganizationSupportAction::where('target_id', $project->id)
            ->where('action', 'project.delete')
            ->firstOrFail();
        $this->assertSame($session->id, $deleteAction->support_session_id);
        $this->assertSame($admin->id, $deleteAction->actor_user_id);
        $this->assertSame($organization->id, $deleteAction->organization_id);
        $this->assertSame(Project::class, $deleteAction->target_type);
        $this->assertSame('deleted_at', $deleteAction->field);
        $this->assertNull($deleteAction->old_value);
        $this->assertSame((string) $trashed->deleted_at, $deleteAction->new_value);

        // Sprint::factory() also mirrors its own Epic (SprintObserver::created()),
        // so the project ends up with 2 epics total: the manual one plus the
        // sprint's mirror.
        $summaryAction = OrganizationSupportAction::where('target_id', $project->id)
            ->where('action', 'project.delete_cascade_summary')
            ->firstOrFail();
        $this->assertSame($session->id, $summaryAction->support_session_id);
        $this->assertNull($summaryAction->field);
        $this->assertNull($summaryAction->old_value);
        $this->assertSame('tickets:2,sprints:1,epics:2', $summaryAction->new_value);
    }

    /**
     * The cascade itself, proven end to end: deleteProject() never
     * reimplements App\Observers\ProjectObserver::deleting()'s own chunked
     * cascade — it only calls plain $project->delete() — so this proves
     * that cascade actually ran through the Support Center write path,
     * exactly as it would through TicketResource/ProjectResource.
     */
    public function test_delete_project_cascades_to_its_tickets_sprints_and_epics(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertSuccessful();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($epic->id)->trashed());
    }

    /**
     * Symmetric positive case for restoreProject(): undoes the soft delete,
     * cascade-restores the tickets/sprints/epics that were taken down with
     * it (App\Observers\ProjectObserver::restoring()'s own within-window
     * rule), and records the inverse audit row plus the inverse cascade
     * summary.
     */
    public function test_restore_project_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $project->delete();
        $oldDeletedAt = (string) Project::withTrashed()->find($project->id)->deleted_at;

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertSuccessful();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertFalse($epic->fresh()->trashed());

        $restoreAction = OrganizationSupportAction::where('target_id', $project->id)
            ->where('action', 'project.restore')
            ->firstOrFail();
        $this->assertSame($session->id, $restoreAction->support_session_id);
        $this->assertSame('deleted_at', $restoreAction->field);
        $this->assertSame($oldDeletedAt, $restoreAction->old_value);
        $this->assertNull($restoreAction->new_value);

        // 1 ticket + 1 sprint + 2 epics (the manual one plus the sprint's
        // own mirror — both cascade-deleted by $project->delete() above,
        // both within ProjectObserver's restore window).
        $summaryAction = OrganizationSupportAction::where('target_id', $project->id)
            ->where('action', 'project.restore_cascade_summary')
            ->firstOrFail();
        $this->assertNull($summaryAction->field);
        $this->assertSame('tickets:1,sprints:1,epics:2', $summaryAction->old_value);
        $this->assertNull($summaryAction->new_value);
    }

    /**
     * The critical proof for this phase, mirroring deleteTicket()'s/
     * deleteSprint()'s own: a Support Action session — far more commonly
     * available than an Owner-approved Full Access grant, and one that
     * already legitimately reaches changeProjectStatus() via
     * authorizeAction() — must not be able to reach deleteProject() at all.
     * deleteProject() calls authorizeFullAccess() directly rather than
     * authorizeCapability(), so a Support Action session is refused
     * outright.
     */
    public function test_delete_project_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Symmetric proof for restoreProject().
     */
    public function test_restore_project_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_project_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_project_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
    }

    public function test_restore_project_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
    }

    public function test_delete_project_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
    }

    public function test_restore_project_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
    }

    public function test_delete_project_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
    }

    public function test_restore_project_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
    }

    public function test_delete_project_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Negative case: a Full Access session with no grant at all behind it
     * (raw DB tampering / a future bug skipping consumeFullAccessGrant())
     * must be denied — mirrors
     * test_delete_ticket_is_denied_for_a_full_access_session_with_no_grant.
     */
    public function test_delete_project_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 13 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 13 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_project_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 13 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 13 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_project_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * Cross-organization proof: a Full Access session for Organization A
     * must not reach a Project belonging to Organization B — zero
     * mutation, zero audit rows, and (the Phase 13-specific addition) B's
     * children never surface in A's own impact-preview counts either,
     * since deleteProject()'s resolution query is organization-scoped
     * before withCount() ever runs.
     */
    public function test_delete_project_rejects_a_project_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        Ticket::factory()->count(3)->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $projectB->id)
            ->assertForbidden();

        $this->assertFalse($projectB->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_restore_project_rejects_a_project_from_a_different_organization_via_direct_livewire_call(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $projectB->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $projectB->id)
            ->assertForbidden();

        $this->assertTrue(Project::withTrashed()->find($projectB->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * restoreProject()'s own precondition: a project that is not currently
     * trashed is an invalid target. deleteProject() has no equivalent test
     * (its query never uses withTrashed(), so the default SoftDeletingScope
     * already excludes a trashed project — the same reasoning
     * deleteTicket()'s/deleteSprint()'s own comments document).
     */
    public function test_restore_project_rejects_a_project_that_is_not_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertForbidden();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_delete_and_restore_project_capabilities_are_present_in_the_full_access_allowlist(): void
    {
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_project'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_project'));
    }

    /**
     * The impact-preview numbers projects() eager loads (tickets_count/
     * sprints_count/epics_count via withCount()) must reflect only the
     * resolved project's own children — proven here with a second
     * organization's project carrying its own, much larger set of
     * children, to make sure nothing about how withCount() is wired could
     * ever let those leak into this organization's own numbers.
     */
    public function test_project_delete_impact_preview_counts_exclude_cross_organization_children(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        Ticket::factory()->count(2)->create(['project_id' => $projectA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        Ticket::factory()->count(5)->create(['project_id' => $projectB->id]);
        Sprint::factory()->count(3)->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organizationA, 'Support request');

        // Every other ->instance() call site in this file chains through
        // ->call('someMethod') before its assertOk()/assertSuccessful() —
        // PHPStan/Larastan's Livewire return-type extension only resolves
        // TestableLivewire correctly through that ->call() step, not off a
        // bare Livewire::test(...)->assertOk() with no ->call() in between
        // (this is the first place in the suite that needed exactly that
        // shape). The explicit @var below is what actually fixes the
        // false-positive "Call to an undefined method
        // Illuminate\Testing\TestResponse::instance()" — it does not
        // change runtime behavior at all, Livewire::test() always returns
        // a TestableLivewire regardless of which assert*() is chained.
        /** @var \Livewire\Testing\TestableLivewire $component */
        $component = Livewire::test(OrganizationSupportView::class)->assertOk();

        $projects = $component->instance()->projects();
        $this->assertCount(1, $projects);
        $this->assertSame($projectA->id, $projects->first()->id);
        $this->assertSame(2, $projects->first()->tickets_count);
        $this->assertSame(0, $projects->first()->sprints_count);
        $this->assertSame(0, $projects->first()->epics_count);
    }

    /**
     * projectRestoreImpact() — the read method the confirm() dialog calls
     * for a trashed project's Restore button — must report the exact same
     * within-cascade-window counts restoreProject() itself would act on,
     * including correctly excluding a trashed ticket that was deleted
     * independently, long before the project (i.e. NOT part of the
     * cascade), mirroring App\Observers\ProjectObserver::restoring()'s own
     * "not resurrecting an unrelated delete" guarantee.
     */
    public function test_project_restore_impact_reports_correct_counts_within_the_cascade_window(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        // Deleted long before the project — independent delete, must be
        // excluded from the impact preview.
        $independentlyDeletedTicket = Ticket::factory()->create(['project_id' => $project->id]);
        $independentlyDeletedTicket->delete();
        $independentlyDeletedTicket->forceFill(['deleted_at' => now()->subDay()])->saveQuietly();

        $cascadedTicket = Ticket::factory()->create(['project_id' => $project->id]);
        $cascadedSprint = Sprint::factory()->create(['project_id' => $project->id]);

        $project->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        // See test_project_delete_impact_preview_counts_exclude_cross_organization_children()
        // above for why this needs an explicit @var.
        /** @var \Livewire\Testing\TestableLivewire $component */
        $component = Livewire::test(OrganizationSupportView::class)->assertOk();

        $impact = $component->instance()->projectRestoreImpact($project->id);

        $this->assertSame(1, $impact['tickets']);
        $this->assertSame(1, $impact['sprints']);
        // The manual epic count is zero; the sprint's own mirrored epic is
        // the one epic cascaded here.
        $this->assertSame(1, $impact['epics']);

        $this->assertTrue(Ticket::withTrashed()->find($cascadedTicket->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($independentlyDeletedTicket->id)->trashed());
    }

    // -------------------------------------------------------------------
    // Phase 13 — Race & Rollback Proof: same technique as Phase 11/12's own
    // (a test-only Eloquent model event listener forces a failure at a
    // precise point, flushed in a finally block) — but this time the
    // failure point sits *after* ProjectObserver's own chunked cascade has
    // already run its UPDATE queries against every Ticket/Sprint/Epic, to
    // prove the whole cascade (not just the Project row) rolls back
    // together with the audit write.
    // -------------------------------------------------------------------

    /**
     * Failure point: Project::deleted() fires only after
     * ProjectObserver::deleting()'s entire chunked cascade (tickets, then
     * sprints, then epics — each individually soft-deleted via Eloquent
     * delete()) has already run, and after the project's own row has
     * already been UPDATEd — but before either OrganizationSupportAction
     * row is ever created. If the outer DB::transaction() in
     * deleteProject() did not truly wrap the same connection
     * ProjectObserver::deleting()'s own nested transaction uses, the
     * cascade would already be durably committed by the time this forced
     * exception propagates.
     */
    public function test_delete_project_rolls_back_the_project_and_its_full_cascade_when_a_failure_happens_after_the_cascade_completes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticketOne = Ticket::factory()->create(['project_id' => $project->id]);
        $ticketTwo = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $manualEpic = Epic::factory()->create(['project_id' => $project->id]);
        // Captured early, while still live — Sprint::epic() has no
        // ->withTrashed(), so reading it back through $sprint after the
        // cascade would resolve to null once the epic is trashed.
        $mirroredEpic = $sprint->epic;

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Project::deleted(function () {
            throw new \RuntimeException('Forced failure for Phase 13 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteProject', $project->id);
                $this->fail('Expected the forced post-cascade exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Project::flushEventListeners();
        }

        $this->assertFalse($project->fresh()->trashed());
        $this->assertFalse($ticketOne->fresh()->trashed());
        $this->assertFalse($ticketTwo->fresh()->trashed());
        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertFalse($manualEpic->fresh()->trashed());
        $this->assertFalse($mirroredEpic->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->where('target_type', Project::class)->count());
    }

    /**
     * Symmetric failure point for restoreProject(): forced on
     * Project::restored(), which fires after ProjectObserver::restoring()'s
     * own cascade-restore (and SprintObserver::restoring()'s further
     * epic-mirror cascade, one level deeper) has already run, but before
     * either audit row is created.
     */
    public function test_restore_project_rolls_back_the_project_and_its_full_cascade_when_a_failure_happens_after_the_cascade_completes(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $manualEpic = Epic::factory()->create(['project_id' => $project->id]);
        // Captured early, while still live — see the delete-side rollback
        // test's own comment for why $sprint->epic cannot be read back
        // after the cascade trashes it.
        $mirroredEpic = $sprint->epic;
        $project->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Project::restored(function () {
            throw new \RuntimeException('Forced failure for Phase 13 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreProject', $project->id);
                $this->fail('Expected the forced post-cascade exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Project::flushEventListeners();
        }

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($manualEpic->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($mirroredEpic->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->where('target_type', Project::class)->count());
    }

    /**
     * The failure point the two tests above do NOT cover: a genuine
     * mid-cascade partial failure, not merely "after the whole cascade
     * already finished". Two tickets exist under the project;
     * Ticket::deleted() is forced to throw, which fires as soon as the
     * *first* ticket's own cascade-delete UPDATE has already run —
     * ProjectObserver::deleting()'s chunkById() processes tickets (in
     * ascending id order) before it ever reaches sprints/epics, so this
     * exception propagates with exactly one ticket already (transiently)
     * trashed, the second ticket never touched, and the sprint/epic stage
     * never even started. This is the scenario the Phase 13 brief calls
     * out by name as the one requiring empirical, not theoretical, proof:
     * if deleteProject()'s outer DB::transaction() and
     * ProjectObserver::deleting()'s own inner DB::transaction() were not
     * truly the same connection (i.e. the inner one a real savepoint of
     * the outer one), the first ticket would remain durably trashed here
     * even though the project itself, the second ticket, and the sprint/
     * epic would not be.
     */
    public function test_delete_project_rolls_back_the_entire_cascade_when_a_failure_happens_partway_through_it(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticketOne = Ticket::factory()->create(['project_id' => $project->id]);
        $ticketTwo = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::deleted(function () {
            throw new \RuntimeException('Forced failure for Phase 13 mid-cascade rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteProject', $project->id);
                $this->fail('Expected the forced mid-cascade exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 mid-cascade rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        // Not just the project and the untouched second ticket/sprint/epic —
        // the FIRST ticket too, whose own delete() had already run and
        // fired its deleted() event (the very thing that threw) before this
        // exception ever propagated.
        $this->assertFalse($project->fresh()->trashed());
        $this->assertFalse($ticketOne->fresh()->trashed());
        $this->assertFalse($ticketTwo->fresh()->trashed());
        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertFalse($epic->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->where('target_type', Project::class)->count());
    }

    /**
     * Symmetric mid-cascade failure point for restoreProject(): forced on
     * Ticket::restored(), which fires as soon as the first cascaded
     * ticket's own restore UPDATE has already run — before the second
     * ticket, the sprint, or either epic is ever reached.
     */
    public function test_restore_project_rolls_back_the_entire_cascade_when_a_failure_happens_partway_through_it(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticketOne = Ticket::factory()->create(['project_id' => $project->id]);
        $ticketTwo = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;
        $project->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::restored(function () {
            throw new \RuntimeException('Forced failure for Phase 13 mid-cascade rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreProject', $project->id);
                $this->fail('Expected the forced mid-cascade exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 mid-cascade rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        // Everything — including the first ticket, which had already been
        // (transiently) restored before the forced exception — must still
        // read as trashed: the whole cascade-so-far rolls back together.
        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticketOne->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticketTwo->id)->trashed());
        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertTrue(Epic::withTrashed()->find($epic->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->where('target_type', Project::class)->count());
    }

    /**
     * Failure point #2: inside OrganizationSupportAction::create() itself —
     * forced to fail on its very first call, which is the 'project.delete'
     * row (the cascade-summary row is never even attempted). Proves a
     * failure confined entirely to the first audit write still rolls back
     * the already-completed cascade too. A retry against the same
     * still-active session must still succeed afterward.
     */
    public function test_delete_project_rolls_back_the_project_and_its_full_cascade_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 13 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('deleteProject', $project->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertFalse($project->fresh()->trashed());
        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('deleteProject', $project->id)
            ->assertSuccessful();

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());

        $action = OrganizationSupportAction::where('target_id', $project->id)->where('action', 'project.delete')->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    /**
     * Symmetric audit-insert-fails rollback proof for restoreProject().
     */
    public function test_restore_project_rolls_back_the_project_and_its_full_cascade_when_the_audit_insert_itself_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $project->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAction::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 13 rollback proof — test-only, never in production code');
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('restoreProject', $project->id);
                $this->fail('Expected the forced audit-insert exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 13 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportAction::flushEventListeners();
        }

        $this->assertTrue(Project::withTrashed()->find($project->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::where('target_id', $project->id)->count());

        Livewire::test(OrganizationSupportView::class)
            ->call('restoreProject', $project->id)
            ->assertSuccessful();

        $this->assertFalse($project->fresh()->trashed());
        $this->assertFalse($ticket->fresh()->trashed());

        $action = OrganizationSupportAction::where('target_id', $project->id)->where('action', 'project.restore')->firstOrFail();
        $this->assertSame($session->id, $action->support_session_id);
    }

    // -------------------------------------------------------------------
    // Phase 14 — Bulk Full Access Gate. bulkDeleteTicket()/bulkRestoreTicket()/
    // bulkDeleteSprint()/bulkRestoreSprint() apply deleteTicket()'s/
    // restoreTicket()'s/deleteSprint()'s/restoreSprint()'s exact per-target
    // mutation to a whole client-selected batch, Full-Access-only exactly
    // like those four. Every capability below is proven for: positive
    // (whole batch mutated, N audit rows), all-or-nothing (one bad target
    // rejects the whole batch, 0 mutations, 0 audit rows), mixed-organization
    // (also rejected wholesale — the same all-or-nothing path, since a
    // wrong-organization id is simply never resolved), the full negative
    // standard already established by Phase 11/12, and a mid-batch rollback
    // proof (the middle item's forced failure must roll back every item,
    // including ones already mutated before it).
    // -------------------------------------------------------------------

    /**
     * Positive case: a genuinely valid, consumed, un-revoked Full Access
     * grant may soft-delete a whole batch of tickets in one call — every
     * ticket trashed, one 'ticket.bulk_delete' audit row per ticket.
     */
    public function test_bulk_delete_ticket_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $tickets = Ticket::factory()->count(3)->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', $tickets->pluck('id')->all())
            ->assertSuccessful();

        foreach ($tickets as $ticket) {
            $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        }

        $actions = OrganizationSupportAction::where('action', 'ticket.bulk_delete')->get();
        $this->assertSame(3, $actions->count());

        foreach ($tickets as $ticket) {
            $action = $actions->firstWhere('target_id', $ticket->id);
            $this->assertNotNull($action);
            $this->assertSame($session->id, $action->support_session_id);
            $this->assertSame($admin->id, $action->actor_user_id);
            $this->assertSame($organization->id, $action->organization_id);
            $this->assertSame(Ticket::class, $action->target_type);
            $this->assertSame('deleted_at', $action->field);
            $this->assertNull($action->old_value);
        }
    }

    /**
     * ALL-OR-NOTHING: one already-trashed ticket in the batch (an invalid
     * target for delete, same precondition deleteTicket() itself enforces
     * via the default SoftDeletingScope) must reject the entire batch — the
     * otherwise-valid ticket in the same call must NOT be deleted either.
     */
    public function test_bulk_delete_ticket_rejects_the_whole_batch_when_one_target_is_already_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $validTicket = Ticket::factory()->create(['project_id' => $project->id]);
        $alreadyTrashedTicket = Ticket::factory()->create(['project_id' => $project->id]);
        $alreadyTrashedTicket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$validTicket->id, $alreadyTrashedTicket->id])
            ->assertForbidden();

        $this->assertFalse($validTicket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * MIXED ORGANIZATION: a batch spanning Organization A (the active
     * session's own target) and Organization B must be rejected wholesale —
     * no separate code path is needed for this (see resolveBulkTickets()'s
     * own docblock): $ticketB is simply never resolved by the
     * organization-scoped query, so the same "every requested id must
     * resolve" count check that catches a bad id also catches this.
     */
    public function test_bulk_delete_ticket_rejects_the_whole_batch_for_mixed_organization_input(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$ticketA->id, $ticketB->id])
            ->assertForbidden();

        $this->assertFalse($ticketA->fresh()->trashed());
        $this->assertFalse($ticketB->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_for_a_support_action_session(): void
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
            ->call('bulkDeleteTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_bulk_delete_ticket_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_bulk_delete_ticket_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
    }

    public function test_bulk_delete_ticket_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_ticket_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * BULK ROLLBACK: forced failure on the *middle* ticket of a 3-item
     * batch — proving every item rolls back together, including whichever
     * one(s) were already soft-deleted by the time the forced failure fired
     * (Ticket::query()->whereIn(...)->get() has no explicit ordering, so
     * this does not assume which specific ticket is processed first; the
     * only thing that matters is that ALL THREE remain untouched after the
     * exception propagates, not just the one that threw).
     */
    public function test_bulk_delete_ticket_rolls_back_every_item_when_a_failure_happens_mid_batch(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $tickets = Ticket::factory()->count(3)->create(['project_id' => $project->id]);
        $middle = $tickets[1];

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::deleted(function (Ticket $ticket) use ($middle) {
            if ($ticket->id === $middle->id) {
                throw new \RuntimeException('Forced failure for Phase 14 bulk rollback proof — test-only, never in production code');
            }
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('bulkDeleteTicket', $tickets->pluck('id')->all());
                $this->fail('Expected the forced mid-batch exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 14 bulk rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        foreach ($tickets as $ticket) {
            $this->assertFalse($ticket->fresh()->trashed());
        }
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // -------------------------------------------------------------------
    // bulkRestoreTicket() — symmetric undo of bulkDeleteTicket() above.
    // -------------------------------------------------------------------

    public function test_bulk_restore_ticket_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $tickets = Ticket::factory()->count(3)->create(['project_id' => $project->id]);
        $tickets->each->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', $tickets->pluck('id')->all())
            ->assertSuccessful();

        foreach ($tickets as $ticket) {
            $this->assertFalse($ticket->fresh()->trashed());
        }

        $actions = OrganizationSupportAction::where('action', 'ticket.bulk_restore')->get();
        $this->assertSame(3, $actions->count());

        foreach ($tickets as $ticket) {
            $action = $actions->firstWhere('target_id', $ticket->id);
            $this->assertNotNull($action);
            $this->assertSame($session->id, $action->support_session_id);
            $this->assertSame($admin->id, $action->actor_user_id);
            $this->assertSame($organization->id, $action->organization_id);
            $this->assertSame(Ticket::class, $action->target_type);
            $this->assertSame('deleted_at', $action->field);
            $this->assertNull($action->new_value);
        }
    }

    /**
     * ALL-OR-NOTHING: one NOT-trashed ticket in the batch (an invalid target
     * for restore, symmetric to restoreTicket()'s own trashed() precondition)
     * must reject the entire batch — the otherwise-valid trashed ticket in
     * the same call must NOT be restored either.
     */
    public function test_bulk_restore_ticket_rejects_the_whole_batch_when_one_target_is_not_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $trashedTicket = Ticket::factory()->create(['project_id' => $project->id]);
        $trashedTicket->delete();
        $liveTicket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$trashedTicket->id, $liveTicket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($trashedTicket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_rejects_the_whole_batch_for_mixed_organization_input(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);
        $ticketA->delete();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);
        $ticketB->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticketA->id, $ticketB->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticketA->id)->trashed());
        $this->assertTrue(Ticket::withTrashed()->find($ticketB->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_bulk_restore_ticket_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_bulk_restore_ticket_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
    }

    public function test_bulk_restore_ticket_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreTicket', [$ticket->id])
            ->assertForbidden();

        $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_ticket_rolls_back_every_item_when_a_failure_happens_mid_batch(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $tickets = Ticket::factory()->count(3)->create(['project_id' => $project->id]);
        $tickets->each->delete();
        $middle = $tickets[1];

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Ticket::restored(function (Ticket $ticket) use ($middle) {
            if ($ticket->id === $middle->id) {
                throw new \RuntimeException('Forced failure for Phase 14 bulk rollback proof — test-only, never in production code');
            }
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('bulkRestoreTicket', $tickets->pluck('id')->all());
                $this->fail('Expected the forced mid-batch exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 14 bulk rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Ticket::flushEventListeners();
        }

        foreach ($tickets as $ticket) {
            $this->assertTrue(Ticket::withTrashed()->find($ticket->id)->trashed());
        }
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // -------------------------------------------------------------------
    // bulkDeleteSprint() — same shape as bulkDeleteTicket() above, applied
    // to Sprint. deleteSprint()'s own Phase 12 finding (SprintObserver has
    // no deleting()/deleted() hook) still holds, so no epic cascade to
    // prove here.
    // -------------------------------------------------------------------

    public function test_bulk_delete_sprint_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprints = Sprint::factory()->count(3)->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', $sprints->pluck('id')->all())
            ->assertSuccessful();

        foreach ($sprints as $sprint) {
            $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        }

        $actions = OrganizationSupportAction::where('action', 'sprint.bulk_delete')->get();
        $this->assertSame(3, $actions->count());

        foreach ($sprints as $sprint) {
            $action = $actions->firstWhere('target_id', $sprint->id);
            $this->assertNotNull($action);
            $this->assertSame($session->id, $action->support_session_id);
            $this->assertSame($admin->id, $action->actor_user_id);
            $this->assertSame($organization->id, $action->organization_id);
            $this->assertSame(Sprint::class, $action->target_type);
            $this->assertSame('deleted_at', $action->field);
            $this->assertNull($action->old_value);
        }

        // Same Phase 12 finding as deleteSprint() itself: no epic is ever
        // touched by a bulk delete either.
        $this->assertSame(0, OrganizationSupportAction::where('target_type', Epic::class)->count());
    }

    public function test_bulk_delete_sprint_rejects_the_whole_batch_when_one_target_is_already_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $validSprint = Sprint::factory()->create(['project_id' => $project->id]);
        $alreadyTrashedSprint = Sprint::factory()->create(['project_id' => $project->id]);
        $alreadyTrashedSprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$validSprint->id, $alreadyTrashedSprint->id])
            ->assertForbidden();

        $this->assertFalse($validSprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_rejects_the_whole_batch_for_mixed_organization_input(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        $sprintA = Sprint::factory()->create(['project_id' => $projectA->id]);

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$sprintA->id, $sprintB->id])
            ->assertForbidden();

        $this->assertFalse($sprintA->fresh()->trashed());
        $this->assertFalse($sprintB->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_for_a_support_action_session(): void
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
            ->call('bulkDeleteSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_bulk_delete_sprint_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_bulk_delete_sprint_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
    }

    public function test_bulk_delete_sprint_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkDeleteSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertFalse($sprint->fresh()->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_delete_sprint_rolls_back_every_item_when_a_failure_happens_mid_batch(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprints = Sprint::factory()->count(3)->create(['project_id' => $project->id]);
        $middle = $sprints[1];

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Sprint::deleted(function (Sprint $sprint) use ($middle) {
            if ($sprint->id === $middle->id) {
                throw new \RuntimeException('Forced failure for Phase 14 bulk rollback proof — test-only, never in production code');
            }
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('bulkDeleteSprint', $sprints->pluck('id')->all());
                $this->fail('Expected the forced mid-batch exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 14 bulk rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Sprint::flushEventListeners();
        }

        foreach ($sprints as $sprint) {
            $this->assertFalse($sprint->fresh()->trashed());
        }
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    // -------------------------------------------------------------------
    // bulkRestoreSprint() — symmetric undo of bulkDeleteSprint() above, plus
    // the per-item epic-mirror cascade restoreSprint() itself already has
    // (Phase 12): each sprint in the batch that has its own independently-
    // trashed mirrored epic gets that epic cascade-restored and audited as
    // its own 'epic.auto_restore' row, exactly like the single-target
    // method — a mechanical, per-item application of already-approved
    // behavior, not a new decision.
    // -------------------------------------------------------------------

    public function test_bulk_restore_sprint_succeeds_with_an_active_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprints = Sprint::factory()->count(3)->create(['project_id' => $project->id]);
        $sprints->each->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', $sprints->pluck('id')->all())
            ->assertSuccessful();

        foreach ($sprints as $sprint) {
            $this->assertFalse($sprint->fresh()->trashed());
        }

        $actions = OrganizationSupportAction::where('action', 'sprint.bulk_restore')->get();
        $this->assertSame(3, $actions->count());

        foreach ($sprints as $sprint) {
            $action = $actions->firstWhere('target_id', $sprint->id);
            $this->assertNotNull($action);
            $this->assertSame($session->id, $action->support_session_id);
            $this->assertSame($admin->id, $action->actor_user_id);
            $this->assertSame($organization->id, $action->organization_id);
            $this->assertSame(Sprint::class, $action->target_type);
            $this->assertSame('deleted_at', $action->field);
            $this->assertNull($action->new_value);
        }

        // None of these sprints' epics were independently trashed, so no
        // cascade-restore/audit row is expected here.
        $this->assertSame(0, OrganizationSupportAction::where('target_type', Epic::class)->count());
    }

    /**
     * The per-item epic-mirror cascade: one of the three sprints being
     * restored also has its own mirrored epic independently trashed
     * beforehand — restoring the batch must cascade-restore that one epic
     * (via SprintObserver::restoring(), unchanged from Phase 12) and record
     * its own separate 'epic.auto_restore' audit row, while the other two
     * sprints' epics (never trashed) are left alone entirely.
     */
    public function test_bulk_restore_sprint_also_restores_a_separately_trashed_mirrored_epic_for_one_item(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprints = Sprint::factory()->count(3)->create(['project_id' => $project->id]);
        $sprintWithTrashedEpic = $sprints[1];
        $epic = $sprintWithTrashedEpic->epic;

        $epic->delete();
        $sprints->each->delete();
        $oldEpicDeletedAt = (string) Epic::withTrashed()->find($epic->id)->deleted_at;

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', $sprints->pluck('id')->all())
            ->assertSuccessful();

        foreach ($sprints as $sprint) {
            $this->assertFalse($sprint->fresh()->trashed());
        }
        $this->assertFalse($epic->fresh()->trashed());

        $epicActions = OrganizationSupportAction::where('target_type', Epic::class)->get();
        $this->assertSame(1, $epicActions->count());

        $epicAction = $epicActions->first();
        $this->assertSame($session->id, $epicAction->support_session_id);
        $this->assertSame($admin->id, $epicAction->actor_user_id);
        $this->assertSame($organization->id, $epicAction->organization_id);
        $this->assertSame('epic.auto_restore', $epicAction->action);
        $this->assertSame($epic->id, $epicAction->target_id);
        $this->assertSame('deleted_at', $epicAction->field);
        $this->assertSame($oldEpicDeletedAt, $epicAction->old_value);
        $this->assertNull($epicAction->new_value);

        $this->assertSame(3, OrganizationSupportAction::where('action', 'sprint.bulk_restore')->count());
    }

    public function test_bulk_restore_sprint_rejects_the_whole_batch_when_one_target_is_not_trashed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $trashedSprint = Sprint::factory()->create(['project_id' => $project->id]);
        $trashedSprint->delete();
        $liveSprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$trashedSprint->id, $liveSprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($trashedSprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_rejects_the_whole_batch_for_mixed_organization_input(): void
    {
        $admin = $this->superAdmin();

        $organizationA = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organizationA->id]);
        $sprintA = Sprint::factory()->create(['project_id' => $projectA->id]);
        $sprintA->delete();

        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);
        $sprintB->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organizationA);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprintA->id, $sprintB->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprintA->id)->trashed());
        $this->assertTrue(Sprint::withTrashed()->find($sprintB->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_for_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_for_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_for_a_non_super_admin(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_bulk_restore_sprint_is_denied_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_bulk_restore_sprint_is_denied_for_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
    }

    public function test_bulk_restore_sprint_is_denied_for_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);
        SupportSessionContext::stop($admin);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_for_a_full_access_session_with_no_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => null,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_for_a_full_access_grant_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $grant->id,
            'reason' => 'Forced session for a Phase 14 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        session(['support_session_id' => $session->id]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    public function test_bulk_restore_sprint_is_denied_once_the_consumed_grant_is_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->delete();

        $this->actingAs($admin);
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        Livewire::test(OrganizationSupportView::class)
            ->call('bulkRestoreSprint', [$sprint->id])
            ->assertForbidden();

        $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }

    /**
     * BULK ROLLBACK for restore, with the epic cascade in play: the middle
     * sprint's forced failure must roll back not only every sprint's own
     * restore, but also the epic cascade-restore that would otherwise have
     * already run for it — proving the whole per-item write (sprint +
     * its own epic cascade + its own audit row) is one atomic unit inside
     * the same outer transaction as every other item in the batch.
     */
    public function test_bulk_restore_sprint_rolls_back_every_item_and_the_epic_cascade_when_a_failure_happens_mid_batch(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $sprints = Sprint::factory()->count(3)->create(['project_id' => $project->id]);
        $middle = $sprints[1];
        $epic = $middle->epic;

        $epic->delete();
        $sprints->each->delete();

        $this->actingAs($admin);
        $this->activeFullAccessSession($admin, $organization);

        Sprint::restored(function (Sprint $sprint) use ($middle) {
            if ($sprint->id === $middle->id) {
                throw new \RuntimeException('Forced failure for Phase 14 bulk rollback proof — test-only, never in production code');
            }
        });

        try {
            try {
                Livewire::test(OrganizationSupportView::class)
                    ->call('bulkRestoreSprint', $sprints->pluck('id')->all());
                $this->fail('Expected the forced mid-batch exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 14 bulk rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            Sprint::flushEventListeners();
        }

        foreach ($sprints as $sprint) {
            $this->assertTrue(Sprint::withTrashed()->find($sprint->id)->trashed());
        }
        $this->assertTrue(Epic::withTrashed()->find($epic->id)->trashed());
        $this->assertSame(0, OrganizationSupportAction::count());
    }
}

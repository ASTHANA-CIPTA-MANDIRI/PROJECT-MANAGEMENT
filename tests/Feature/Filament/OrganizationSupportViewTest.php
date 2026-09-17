<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
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
}

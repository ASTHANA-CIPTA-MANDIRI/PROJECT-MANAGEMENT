<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Filament\Pages\PlatformOrganizations;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationSupportSession;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Super Admin only, read-only, cross-organization view (ADR 0001,
 * "Platform" section). Authorization here is deliberately NOT
 * OrganizationContext/OrganizationPolicy — see the class docblock on
 * PlatformOrganizations for why — so these tests pin down that a plain
 * Organization member (even an Owner) is refused just like anyone else,
 * only User::isSuperAdmin() opens the page.
 */
class PlatformOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    // ----------------------------------------------------------- access

    public function test_super_admin_can_open_the_page(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)->assertSuccessful();
    }

    /**
     * The whole point of the page: every Organization is listed, including
     * one the Super Admin holds no membership in at all — proving this does
     * not go through OrganizationContext/OrganizationPolicy, both of which
     * are scoped to "organizations this user belongs to".
     */
    public function test_super_admin_sees_every_organization_including_ones_they_do_not_belong_to(): void
    {
        $admin = $this->superAdmin();

        $ownOrganization = Organization::factory()->create(['name' => 'Own Org']);
        $ownOrganization->users()->attach($admin->id, ['role' => 'member']);

        // Deliberately not attaching $admin to this organization at all.
        Organization::factory()->create(['name' => 'Stranger Org']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Own Org')
            ->assertSee('Stranger Org');
    }

    /**
     * A regular, non-Super-Admin panel user is refused — even one who owns
     * an organization of their own. Owning/managing an organization is a
     * different axis (OrganizationPolicy) from the platform-level access
     * this page requires.
     */
    public function test_a_non_super_admin_is_denied_access(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();
    }

    public function test_opening_the_page_url_directly_without_super_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PlatformOrganizations::getUrl())->assertForbidden();
    }

    /**
     * The navigation entry being hidden is a UX nicety only — the route
     * stays registered, so the page itself must still refuse a direct hit.
     * Mirrors CustomPageAuthorizationTest's equivalent guard for the
     * permission-based pages.
     */
    public function test_hiding_the_navigation_entry_is_not_the_authorization(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(PlatformOrganizations::userCanAccessPage());
        $this->assertNotNull(PlatformOrganizations::getUrl());
        Livewire::test(PlatformOrganizations::class)->assertForbidden();
    }

    // ----------------------------------------------------- trial status

    public function test_a_grandfathered_organization_is_labelled_grandfathered(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create(['name' => 'Grandfathered Co', 'trial_ends_at' => null]);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Grandfathered'));
    }

    public function test_an_organization_still_on_trial_shows_days_remaining(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create([
            'name' => 'Trialing Co',
            'trial_ends_at' => now()->addDays(3),
        ]);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Trial active (:days days left)', ['days' => 3]));
    }

    public function test_an_organization_past_its_trial_and_unsubscribed_shows_trial_ended(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->trialExpired()->create(['name' => 'Expired Co']);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Trial ended'));
    }

    // ------------------------------------------------------- other columns

    public function test_owner_name_is_shown_on_the_page(): void
    {
        $admin = $this->superAdmin();
        $owner = User::factory()->create(['name' => 'Ollie Owner', 'email' => 'ollie@example.test']);
        $member = User::factory()->create();

        $organization = Organization::factory()->create(['name' => 'Staffed Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $organization->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Ollie Owner');
    }

    /**
     * assertSee() is unreliable for the other two values checked here: a
     * bare digit like the member count also matches dates elsewhere on the
     * page, and the email column renders as an obfuscated `mailto:` link
     * (the `@` is HTML-entity-encoded), so raw string matching against the
     * rendered HTML would not find it either. The underlying query's
     * computed values are asserted directly instead.
     */
    public function test_member_count_and_owner_are_computed_correctly(): void
    {
        $admin = $this->superAdmin();
        $owner = User::factory()->create(['name' => 'Ollie Owner', 'email' => 'ollie@example.test']);
        $member = User::factory()->create();

        $organization = Organization::factory()->create(['name' => 'Staffed Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $organization->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin);

        $page = new PlatformOrganizations;
        $method = new \ReflectionMethod($page, 'getTableQuery');
        $method->setAccessible(true);

        $record = $method->invoke($page)->whereKey($organization->id)->first();

        $this->assertSame(2, $record->users_count);
        $this->assertSame('Ollie Owner', $record->users->first()?->name);
        $this->assertSame('ollie@example.test', $record->users->first()?->email);
    }

    // -------------------------------------------------------------- create

    public function test_super_admin_can_create_an_organization_with_an_owner(): void
    {
        $this->actingAs($this->superAdmin());

        $owner = User::factory()->create(['name' => 'New Owner', 'email' => 'new-owner@example.test']);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Freshly Created Co',
                'owner_id' => $owner->id,
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $organization = Organization::where('name', 'Freshly Created Co')->firstOrFail();

        $this->assertTrue($organization->isOwnedBy($owner->fresh()));
        $this->assertNull($organization->trial_ends_at);
        // OrganizationDefaults::seed() ran - the same starter reference data
        // every other creation path (self-serve, CreateOrganization) gets,
        // so this organization is immediately usable, not a broken shell.
        $this->assertTrue(ProjectStatus::where('organization_id', $organization->id)->exists());
    }

    /**
     * A user picked as Owner who holds no Spatie Role at all (e.g. a
     * standalone account created without one) must not end up locked out
     * of Filament entirely — User::canAccessFilament() gates purely on
     * roles()->exists(), independent of organization_users.role.
     */
    public function test_creating_an_organization_grants_the_owner_role_when_the_picked_owner_has_none(): void
    {
        $this->actingAs($this->superAdmin());

        Role::create(['name' => 'Owner']);
        $owner = User::factory()->create();
        $this->assertFalse($owner->roles()->exists());

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Freshly Created Co',
                'owner_id' => $owner->id,
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($owner->fresh()->hasRole('Owner'));
    }

    /**
     * An owner who already holds an established Role elsewhere must never
     * have it silently replaced — Spatie Roles are global per-user, not
     * Organization-scoped (ADR 0001's Teams rejection), so overwriting it
     * here would change what they can do in every other Organization they
     * already belong to.
     */
    public function test_creating_an_organization_never_overwrites_the_picked_owners_existing_role(): void
    {
        $this->actingAs($this->superAdmin());

        Role::create(['name' => 'Owner']);
        $existingRole = Role::create(['name' => 'Admin']);
        $owner = User::factory()->create();
        $owner->assignRole($existingRole);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Freshly Created Co',
                'owner_id' => $owner->id,
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $owner->refresh();
        $this->assertTrue($owner->hasRole('Admin'));
        $this->assertFalse($owner->hasRole('Owner'));
    }

    public function test_creating_an_organization_with_a_duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create(['name' => 'Taken Co']);
        $owner = User::factory()->create();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Taken Co',
                'owner_id' => $owner->id,
            ])
            ->assertHasTableActionErrors(['name']);
    }

    public function test_creating_an_organization_with_a_blank_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        $owner = User::factory()->create();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => '   ',
                'owner_id' => $owner->id,
            ])
            ->assertHasTableActionErrors(['name']);
    }

    public function test_creating_an_organization_without_a_valid_owner_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Ownerless Co',
                'owner_id' => 999999,
            ])
            ->assertHasTableActionErrors(['owner_id']);

        $this->assertFalse(Organization::where('name', 'Ownerless Co')->exists());
    }

    // ------------------------------------------- create via email invite

    /**
     * The "invite someone new" branch for an email with no account yet:
     * the Organization exists immediately (visible in the table right
     * away), but nobody is attached as Owner — only accepting the emailed
     * invitation does that (App\Http\Livewire\AcceptOrganizationInvitation),
     * exactly like every other invitation in this app.
     */
    public function test_super_admin_can_create_an_organization_by_inviting_a_brand_new_owner_by_email(): void
    {
        NotificationFacade::fake();

        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Invited Co',
                'owner_mode' => 'invite',
                'invite_name' => 'Future Owner',
                'invite_email' => 'future-owner@example.test',
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $organization = Organization::where('name', 'Invited Co')->firstOrFail();

        $this->assertSame(0, $organization->users()->count());
        // OrganizationDefaults::seed() still ran even though nobody is a
        // member yet - the org must be immediately usable once someone
        // does accept, not a broken shell waiting to be finished.
        $this->assertTrue(ProjectStatus::where('organization_id', $organization->id)->exists());

        $invitation = OrganizationInvitation::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('owner', $invitation->role);
        $this->assertSame('future-owner@example.test', $invitation->email);
        $this->assertSame('Future Owner', $invitation->name);
        $this->assertTrue($invitation->isPending());

        NotificationFacade::assertSentOnDemand(OrganizationInvitationCreated::class);
    }

    /**
     * An email that already belongs to an account is attached immediately
     * instead of going through an invitation — mirrors the precedent
     * OrganizationSettings::addExistingUser() already sets for "existing
     * account short-circuits the invite".
     */
    public function test_creating_an_organization_by_inviting_an_email_that_already_has_an_account_attaches_immediately(): void
    {
        NotificationFacade::fake();

        $this->actingAs($this->superAdmin());

        $existingUser = User::factory()->create(['email' => 'already-here@example.test']);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'Shortcut Co',
                'owner_mode' => 'invite',
                'invite_name' => 'Ignored Name',
                'invite_email' => 'already-here@example.test',
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $organization = Organization::where('name', 'Shortcut Co')->firstOrFail();

        $this->assertTrue($organization->isOwnedBy($existingUser->fresh()));
        $this->assertFalse(OrganizationInvitation::where('organization_id', $organization->id)->exists());
        NotificationFacade::assertNothingSent();
    }

    public function test_creating_an_organization_via_invite_without_an_email_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'No Email Co',
                'owner_mode' => 'invite',
                'invite_name' => 'Someone',
                'invite_email' => '',
            ])
            ->assertHasTableActionErrors(['invite_email']);

        $this->assertFalse(Organization::where('name', 'No Email Co')->exists());
    }

    public function test_creating_an_organization_via_invite_without_a_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: [
                'name' => 'No Name Co',
                'owner_mode' => 'invite',
                'invite_name' => '',
                'invite_email' => 'someone@example.test',
            ])
            ->assertHasTableActionErrors(['invite_name']);

        $this->assertFalse(Organization::where('name', 'No Name Co')->exists());
    }

    // ---------------------------------------------- revoke owner invite

    public function test_super_admin_can_revoke_a_pending_owner_invitation(): void
    {
        $this->actingAs($this->superAdmin());

        $organization = Organization::factory()->create(['name' => 'Awaiting Co']);
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'owner',
        ]);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('revokeOwnerInvitation', $organization);

        $this->assertTrue($invitation->fresh()->isRevoked());
    }

    public function test_revoke_owner_invitation_action_is_hidden_once_the_organization_has_an_owner(): void
    {
        $this->actingAs($this->superAdmin());

        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Staffed Already Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionHidden('revokeOwnerInvitation', $organization);
    }

    public function test_a_non_super_admin_cannot_reach_the_create_action(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        // The whole component is refused before any action could run -
        // AuthorizesPageAccess re-checks userCanAccessPage() on every
        // Livewire request, this action included.
        Livewire::test(PlatformOrganizations::class)->assertForbidden();
    }

    // ---------------------------------------------------------------- edit

    public function test_super_admin_can_edit_an_organizations_name_and_trial(): void
    {
        $this->actingAs($this->superAdmin());

        $organization = Organization::factory()->create([
            'name' => 'Old Name Co',
            'trial_ends_at' => now()->addDays(3),
        ]);

        $newTrialEnd = now()->addDays(30)->startOfDay();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: [
                'name' => 'New Name Co',
                'trial_ends_at' => $newTrialEnd->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $organization->refresh();

        $this->assertSame('New Name Co', $organization->name);
        $this->assertTrue($organization->trial_ends_at->isSameDay($newTrialEnd));
    }

    public function test_editing_an_organization_to_a_grandfathered_state_clears_trial_ends_at(): void
    {
        $this->actingAs($this->superAdmin());

        $organization = Organization::factory()->create([
            'name' => 'Trialing Co',
            'trial_ends_at' => now()->addDays(3),
        ]);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: [
                'name' => 'Trialing Co',
                'trial_ends_at' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNull($organization->fresh()->trial_ends_at);
    }

    public function test_renaming_an_organization_to_another_organizations_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create(['name' => 'Taken Co']);
        $organization = Organization::factory()->create(['name' => 'Mine Co']);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: [
                'name' => 'Taken Co',
                'trial_ends_at' => null,
            ])
            ->assertHasTableActionErrors(['name']);

        $this->assertSame('Mine Co', $organization->fresh()->name);
    }

    // -------------------------------------------------------------- delete

    public function test_super_admin_can_delete_an_organization_with_no_projects(): void
    {
        $this->actingAs($this->superAdmin());

        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Doomed Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $organization->id]);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('deleteOrganization', $organization);

        $this->assertModelMissing($organization);
        // cascadeOnDelete on organization_users/organization_invitations
        // (see the migrations) means both are gone too, not just the
        // Organization row itself.
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id]);
        $this->assertModelMissing($invitation);
    }

    public function test_deleting_an_organization_with_an_active_project_is_blocked(): void
    {
        $this->actingAs($this->superAdmin());

        $organization = Organization::factory()->create(['name' => 'Busy Co']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('deleteOrganization', $organization);

        $this->assertModelExists($organization);
        $this->assertModelExists($project);
    }

    /**
     * A soft-deleted Project still has its status_id/priority_id/type_id
     * foreign keys pointing at this organization's lookup rows, so the
     * cascade would still hit a database constraint - the proactive check
     * uses withTrashed() specifically to catch this case before it does.
     */
    public function test_deleting_an_organization_with_only_a_trashed_project_is_still_blocked(): void
    {
        $this->actingAs($this->superAdmin());

        $organization = Organization::factory()->create(['name' => 'Formerly Busy Co']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->delete();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('deleteOrganization', $organization);

        $this->assertModelExists($organization);
    }

    public function test_a_non_super_admin_cannot_reach_the_delete_action(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();

        $this->assertModelExists($organization);
    }

    // ------------------------------------------------- start support session

    /**
     * The whole point of this action: Project::isAccessibleBy() et al. have
     * no Super Admin bypass anywhere, so this is the only way a Super Admin
     * ever gets a legitimate window into another Organization's data — and
     * it must always be logged with a reason.
     */
    public function test_super_admin_can_start_a_support_session_with_a_reason(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['name' => 'Needs Help Co']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('startSupportSession', $organization, data: [
                'reason' => 'Investigating a ticket the customer reported as broken',
            ])
            ->assertHasNoTableActionErrors()
            ->assertRedirect(OrganizationSupportView::getUrl());

        $session = OrganizationSupportSession::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame($admin->id, $session->super_admin_id);
        $this->assertSame('Investigating a ticket the customer reported as broken', $session->reason);
        $this->assertNull($session->ended_at);
    }

    public function test_starting_a_support_session_without_a_reason_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('startSupportSession', $organization, data: [
                'reason' => '',
            ])
            ->assertHasTableActionErrors(['reason']);

        $this->assertFalse(
            OrganizationSupportSession::where('organization_id', $organization->id)
                ->where('super_admin_id', $admin->id)
                ->exists()
        );
    }

    public function test_a_non_super_admin_cannot_reach_the_start_support_session_action(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();

        $this->assertFalse(OrganizationSupportSession::where('organization_id', $organization->id)->exists());
    }
}

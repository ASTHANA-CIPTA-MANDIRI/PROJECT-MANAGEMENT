<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
use App\Models\OrganizationSupportAccessGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7 — Full Access UI/UX Gate, Owner side. Mirrors
 * OrganizationInvitationTest's own W/X ("revoke a pending invitation")
 * coverage shape for the same class of risk: a client-supplied id must
 * never substitute for the actor's own current organization. The domain
 * layer itself (SupportSessionContext::approveFullAccessGrant()/
 * revokeFullAccessGrant()) is already exhaustively covered by
 * tests/Feature/Support/SupportSessionContextTest.php (Phase 1-6,
 * untouched here) — this suite only proves the Livewire wiring on top of
 * it: OrganizationSettings::approveFullAccessGrant()/revokeFullAccessGrant()
 * scope their lookup to the actor's own organization before ever calling
 * into SupportSessionContext, exactly like revokeInvitation() already does
 * for invitations.
 */
class OrganizationFullAccessApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user with at least one Spatie Role, so User::canAccessFilament()
     * (which gates purely on roles()->exists()) lets them open the panel —
     * same helper shape as OrganizationInvitationTest::panelUser().
     */
    private function panelUser(): User
    {
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    /**
     * requestFullAccess()/consumeFullAccessGrant() both abort_unless()
     * isSuperAdmin() — the requester in every scenario below must actually
     * hold that role, not just any panel-accessible Role.
     */
    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::firstOrCreate(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    // ------------------------------------------------- visibility (owner only)

    public function test_owner_can_see_a_pending_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        SupportSessionContext::requestFullAccess($admin, $organization, 'Investigating billing issue');

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            // "Full Access requests" is translated to "Permintaan Full
            // Access" in lang/id.json (TranslationCompletenessTest
            // requires every __() literal to have an entry) — app.locale
            // defaults to 'id' (config/app.php), so the rendered section
            // heading is the Indonesian string, not the English literal.
            ->assertSee('Permintaan Full Access')
            ->assertSee($admin->name)
            ->assertSee('Investigating billing issue');
    }

    /**
     * canApproveFullAccess() is deliberately narrower than
     * canManageOrganization() (Owner+Admin) — approveFullAccessGrant()
     * itself only ever accepts the Organization's real Owner, so an Admin
     * must not even see the section, let alone the Approve/Reject buttons.
     */
    public function test_admin_does_not_see_the_full_access_requests_section(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $organization->users()->attach($admin->id, ['role' => 'admin']);
        $requestingAdmin = $this->superAdmin();

        SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');

        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->assertDontSee('Permintaan Full Access');
    }

    public function test_member_does_not_see_the_full_access_requests_section(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->panelUser();
        $organization->users()->attach($member->id, ['role' => 'member']);
        $requestingAdmin = $this->superAdmin();

        SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');

        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->assertDontSee('Permintaan Full Access');
    }

    // ------------------------------------------------- 6: approver recorded

    public function test_owner_can_approve_a_pending_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('approveFullAccessGrant', $grant->id);

        $grant->refresh();
        $this->assertSame('approved', $grant->status());
        $this->assertSame($owner->id, $grant->approved_by);
    }

    public function test_owner_can_reject_a_pending_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('revokeFullAccessGrant', $grant->id);

        $grant->refresh();
        $this->assertSame('revoked', $grant->status());
        $this->assertSame($owner->id, $grant->revoked_by);
    }

    public function test_owner_can_revoke_an_already_approved_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('revokeFullAccessGrant', $grant->id);

        $this->assertSame('revoked', $grant->fresh()->status());
    }

    // ------------------------------------------------- 4: cross-organization protection

    /**
     * The core client-manipulation test: Owner of Organization B guesses
     * (or is handed) Organization A's grant id and tries to approve it.
     * approveFullAccessGrant() scopes its lookup to
     * $this->organization()->id — Organization B's own current
     * organization, re-verified by OrganizationContext::current() — so the
     * grant is never found at all, exactly like
     * OrganizationInvitationTest::test_admin_of_organization_a_cannot_revoke_an_invitation_from_organization_b().
     * A silent no-op, not an exception, so a guessed id reveals nothing.
     */
    public function test_owner_of_organization_b_cannot_approve_a_grant_belonging_to_organization_a(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->panelUser();
        $ownerB = $this->panelUser();
        $organizationA->users()->attach($ownerA->id, ['role' => 'owner']);
        $organizationB->users()->attach($ownerB->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grantA = SupportSessionContext::requestFullAccess($admin, $organizationA, 'Reason');

        $this->actingAs($ownerB);

        Livewire::test(OrganizationSettings::class)->call('approveFullAccessGrant', $grantA->id);

        $grantA->refresh();
        $this->assertSame('requested', $grantA->status());
        $this->assertNull($grantA->approved_by);
    }

    public function test_owner_of_organization_b_cannot_revoke_a_grant_belonging_to_organization_a(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->panelUser();
        $ownerB = $this->panelUser();
        $organizationA->users()->attach($ownerA->id, ['role' => 'owner']);
        $organizationB->users()->attach($ownerB->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grantA = SupportSessionContext::requestFullAccess($admin, $organizationA, 'Reason');

        $this->actingAs($ownerB);

        Livewire::test(OrganizationSettings::class)->call('revokeFullAccessGrant', $grantA->id);

        $grantA->refresh();
        $this->assertSame('requested', $grantA->status());
        $this->assertNull($grantA->revoked_at);
    }

    // ------------------------------------------------- 9: unauthorized actors denied

    public function test_admin_cannot_approve_a_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $organization->users()->attach($admin->id, ['role' => 'admin']);
        $requestingAdmin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');

        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->call('approveFullAccessGrant', $grant->id)
            ->assertForbidden();

        $this->assertSame('requested', $grant->fresh()->status());
    }

    public function test_member_cannot_approve_a_full_access_request(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->panelUser();
        $organization->users()->attach($member->id, ['role' => 'member']);
        $requestingAdmin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');

        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->call('approveFullAccessGrant', $grant->id)
            ->assertForbidden();

        $this->assertSame('requested', $grant->fresh()->status());
    }

    // ------------------------------------------------- 7/8: status + expiry (owner side)

    public function test_full_access_grant_status_and_expiry_are_exposed_to_the_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($owner);

        $grants = Livewire::test(OrganizationSettings::class)->instance()->fullAccessGrants();

        $this->assertCount(1, $grants);
        $this->assertSame('requested', $grants->first()->status());
        $this->assertTrue($grants->first()->request_expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_full_access_session_status_and_timing_are_exposed_once_consumed(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        $session = SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->actingAs($owner);

        $grants = Livewire::test(OrganizationSettings::class)->instance()->fullAccessGrants();

        $this->assertCount(1, $grants);
        $this->assertSame('consumed', $grants->first()->status());
        $this->assertNotNull($grants->first()->session);
        $this->assertTrue($grants->first()->session->is($session));
        $this->assertTrue($grants->first()->session->isActive());
    }

    // ------------------------------------------------- "scope" is static, informational only

    public function test_no_capability_is_ever_persisted_for_a_full_access_grant(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertArrayNotHasKey('scope', $grant->getAttributes());
        $this->assertArrayNotHasKey('capability', $grant->getAttributes());
    }

    // ------------------------------------------------- deleteFullAccessGrant()

    /**
     * A REQUESTED grant is still awaiting the Owner's own decision — it has
     * its own Approve/Reject controls (approveFullAccessGrant()/
     * revokeFullAccessGrant()) and must not be removable through the
     * cleanup action instead.
     */
    public function test_owner_cannot_delete_a_requested_full_access_grant(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNotNull($grant->fresh());
        $this->assertSame('requested', $grant->fresh()->status());
    }

    /**
     * An APPROVED grant is still consumable into a real session — same
     * "still alive, do not let cleanup touch it" reasoning as the REQUESTED
     * case above.
     */
    public function test_owner_cannot_delete_an_approved_full_access_grant(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNotNull($grant->fresh());
        $this->assertSame('approved', $grant->fresh()->status());
    }

    public function test_owner_can_delete_a_consumed_full_access_grant(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNull(OrganizationSupportAccessGrant::find($grant->id));
    }

    public function test_owner_can_delete_a_revoked_full_access_grant(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($owner, $grant);

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNull(OrganizationSupportAccessGrant::find($grant->id));
    }

    public function test_owner_can_delete_a_grant_that_expired_without_ever_being_approved(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->travel(25)->hours();

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNull(OrganizationSupportAccessGrant::find($grant->id));
    }

    public function test_owner_can_delete_a_grant_that_was_approved_but_expired_before_being_consumed(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->travel(31)->minutes();

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grant->id);

        $this->assertNull(OrganizationSupportAccessGrant::find($grant->id));
    }

    /**
     * Same cross-organization protection as approveFullAccessGrant()/
     * revokeFullAccessGrant() above: the lookup is scoped to Owner B's own
     * current organization, so Organization A's grant is never found at
     * all, even though it is in a deletable (revoked) state.
     */
    public function test_owner_of_organization_b_cannot_delete_a_grant_belonging_to_organization_a(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->panelUser();
        $ownerB = $this->panelUser();
        $organizationA->users()->attach($ownerA->id, ['role' => 'owner']);
        $organizationB->users()->attach($ownerB->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grantA = SupportSessionContext::requestFullAccess($admin, $organizationA, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($admin, $grantA);

        $this->actingAs($ownerB);

        Livewire::test(OrganizationSettings::class)->call('deleteFullAccessGrant', $grantA->id);

        $this->assertNotNull(OrganizationSupportAccessGrant::find($grantA->id));
    }

    // ------------------------------------------------- notification link targets the right Organization

    /**
     * App\Notifications\FullAccessRequested's "Review request" / "view"
     * links carry the grant's Organization id as ?organization=. Before
     * that fix, OrganizationSettings always rendered whichever Organization
     * happened to be active in OrganizationContext (the first one attached,
     * absent a session override) — for an Owner of more than one
     * Organization, that is not necessarily the one the request is about.
     * Attaching Organization B first makes it the default active context,
     * so this proves the query param actually switches to Organization A
     * rather than the page silently rendering B.
     */
    public function test_owner_of_two_organizations_lands_on_the_grants_organization_via_query_param(): void
    {
        $organizationA = Organization::factory()->create(['name' => 'Organization A']);
        $organizationB = Organization::factory()->create(['name' => 'Organization B']);
        $owner = $this->panelUser();
        $organizationB->users()->attach($owner->id, ['role' => 'owner']);
        $organizationA->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        $this->get(OrganizationSettings::getUrl(['organization' => $organizationA->id]))
            ->assertSuccessful()
            ->assertSee('Organization A');
    }

    /**
     * A crafted/stale ?organization= id the current user does not actually
     * belong to must never switch the active context — OrganizationContext
     * ::switch() re-verifies real membership and silently no-ops, so the
     * page keeps rendering whatever was already active instead of erroring
     * or leaking another organization's data.
     */
    public function test_an_organization_id_the_user_does_not_belong_to_is_ignored(): void
    {
        $organizationA = Organization::factory()->create(['name' => 'Organization A']);
        $organizationB = Organization::factory()->create(['name' => 'Organization B']);
        $owner = $this->panelUser();
        $organizationB->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        $this->get(OrganizationSettings::getUrl(['organization' => $organizationA->id]))
            ->assertSuccessful()
            ->assertSee('Organization B');
    }

    // ------------------------------------------------- deleteAllFinishedFullAccessGrants()

    public function test_delete_all_finished_removes_finished_grants_but_leaves_active_ones(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $consumedGrant = SupportSessionContext::requestFullAccess($admin, $organization, 'Consumed');
        $consumedGrant = SupportSessionContext::approveFullAccessGrant($owner, $consumedGrant);
        SupportSessionContext::consumeFullAccessGrant($admin, $consumedGrant);

        $revokedGrant = SupportSessionContext::requestFullAccess($admin, $organization, 'Revoked');
        SupportSessionContext::revokeFullAccessGrant($owner, $revokedGrant);

        $activeGrant = SupportSessionContext::requestFullAccess($admin, $organization, 'Still requested');

        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('deleteAllFinishedFullAccessGrants');

        $this->assertNull(OrganizationSupportAccessGrant::find($consumedGrant->id));
        $this->assertNull(OrganizationSupportAccessGrant::find($revokedGrant->id));
        $this->assertNotNull(OrganizationSupportAccessGrant::find($activeGrant->id));
        $this->assertSame('requested', $activeGrant->fresh()->status());
    }

    /**
     * The bulk cleanup is scoped to $this->organization()->id at the query
     * level (never Model::all() filtered in PHP) — same discipline as
     * fullAccessGrants()/approveFullAccessGrant() above — so a finished
     * grant belonging to a different organization is never touched by
     * another organization's Owner running this action.
     */
    public function test_delete_all_finished_does_not_touch_another_organizations_grants(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->panelUser();
        $ownerB = $this->panelUser();
        $organizationA->users()->attach($ownerA->id, ['role' => 'owner']);
        $organizationB->users()->attach($ownerB->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        $grantA = SupportSessionContext::requestFullAccess($admin, $organizationA, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($admin, $grantA);

        $this->actingAs($ownerB);

        Livewire::test(OrganizationSettings::class)->call('deleteAllFinishedFullAccessGrants');

        $this->assertNotNull(OrganizationSupportAccessGrant::find($grantA->id));
    }

    /**
     * Regression test for a real bug: fullAccessGrants() caps its list at
     * the 20 newest rows (->limit(20)), but the Blade view used to decide
     * whether to show "Delete all finished" by calling ->contains() on that
     * SAME capped collection. With more than 20 grants where the 20 newest
     * are all still "requested" and an older (21st+) grant is already
     * finished, the button never appeared — the Owner had no way to know a
     * finished grant existed at all, even though
     * deleteAllFinishedFullAccessGrants() itself has no limit and would
     * have deleted it. hasFinishedFullAccessGrants() fixes this by running
     * its own unlimited exists() query instead of trusting the capped list.
     */
    public function test_has_finished_full_access_grants_is_true_even_when_the_finished_grant_is_outside_the_top_20(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $admin = $this->superAdmin();

        // The one finished grant: oldest of all (requested 30 days ago,
        // revoked 29 days ago), so it sorts well outside fullAccessGrants()'s
        // top 20 newest-first rows.
        $oldFinishedGrant = OrganizationSupportAccessGrant::create([
            'organization_id' => $organization->id,
            'requested_by' => $admin->id,
            'reason' => 'Old, already handled',
            'requested_at' => now()->subDays(30),
            'request_expires_at' => now()->subDays(30)->addHours(24),
            'revoked_by' => $owner->id,
            'revoked_at' => now()->subDays(29),
        ]);

        // 20 still-active ("requested") grants, all newer than the finished
        // one above, so they fill up fullAccessGrants()'s entire limit(20)
        // and push the finished grant out of that list.
        for ($i = 0; $i < 20; $i++) {
            OrganizationSupportAccessGrant::create([
                'organization_id' => $organization->id,
                'requested_by' => $admin->id,
                'reason' => "Still pending #{$i}",
                'requested_at' => now()->subMinutes(20 - $i),
                'request_expires_at' => now()->addHours(24),
            ]);
        }

        $this->actingAs($owner);

        $component = Livewire::test(OrganizationSettings::class);

        $grants = $component->instance()->fullAccessGrants();
        $this->assertCount(20, $grants);
        $this->assertTrue($grants->every(fn ($grant) => $grant->status() === 'requested'));
        $this->assertFalse($grants->contains(fn ($grant) => $grant->is($oldFinishedGrant)));

        $this->assertTrue($component->instance()->hasFinishedFullAccessGrants());
        $component->assertSee(__('Delete all finished'));
    }
}

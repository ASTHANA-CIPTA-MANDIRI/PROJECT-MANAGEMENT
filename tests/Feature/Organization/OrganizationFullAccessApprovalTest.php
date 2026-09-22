<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
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
}

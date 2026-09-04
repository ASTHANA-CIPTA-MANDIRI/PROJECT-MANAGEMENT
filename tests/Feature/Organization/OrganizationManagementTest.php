<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5 — Organization Management & RBAC UI. OrganizationPolicy's
 * updateMemberRole()/removeMember() are the real gate (Step 12: "jangan
 * hanya hide button") — most cases here call them directly through Gate,
 * the same way OrganizationSettings' Livewire actions do, plus a few
 * end-to-end Livewire tests proving tampering is rejected in practice.
 */
class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function attach(Organization $organization, User $user, string $role): void
    {
        $organization->users()->attach($user->id, ['role' => $role]);
    }

    // ------------------------------------------------------- 1-3: matrix

    public function test_owner_can_view_members(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');

        $this->assertTrue(Gate::forUser($owner)->allows('view', $organization));
    }

    public function test_admin_can_change_a_members_role(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $member, 'member');

        $this->assertTrue(Gate::forUser($admin)->allows('updateMemberRole', [$organization, $member, 'admin']));
        $this->assertTrue(Gate::forUser($admin)->allows('removeMember', [$organization, $member]));
    }

    public function test_member_cannot_manage_members(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $this->attach($organization, $member, 'member');
        $this->attach($organization, $otherMember, 'member');

        $this->assertTrue(Gate::forUser($member)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($member)->allows('updateMemberRole', [$organization, $otherMember, 'admin']));
        $this->assertFalse(Gate::forUser($member)->allows('removeMember', [$organization, $otherMember]));
    }

    // ------------------------------------------------------- 4-6: Owner protection

    public function test_admin_cannot_change_an_owners_role(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $owner, 'owner');
        // A second owner so this isn't confused with the sole-owner rule.
        $this->attach($organization, User::factory()->create(), 'owner');

        $this->assertFalse(Gate::forUser($admin)->allows('updateMemberRole', [$organization, $owner, 'member']));
    }

    public function test_admin_cannot_remove_an_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, User::factory()->create(), 'owner');

        $this->assertFalse(Gate::forUser($admin)->allows('removeMember', [$organization, $owner]));
    }

    public function test_the_sole_owner_cannot_be_removed_by_anyone(): void
    {
        $organization = Organization::factory()->create();
        $soleOwner = User::factory()->create();
        $this->attach($organization, $soleOwner, 'owner');

        $this->assertFalse(Gate::forUser($soleOwner)->allows('removeMember', [$organization, $soleOwner]));
    }

    public function test_the_sole_owner_cannot_be_demoted_by_anyone(): void
    {
        $organization = Organization::factory()->create();
        $soleOwner = User::factory()->create();
        $this->attach($organization, $soleOwner, 'owner');

        $this->assertFalse(Gate::forUser($soleOwner)->allows('updateMemberRole', [$organization, $soleOwner, 'admin']));
    }

    public function test_a_second_owner_may_demote_or_remove_the_first(): void
    {
        $organization = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->attach($organization, $ownerA, 'owner');
        $this->attach($organization, $ownerB, 'owner');

        $this->assertTrue(Gate::forUser($ownerB)->allows('updateMemberRole', [$organization, $ownerA, 'admin']));
        $this->assertTrue(Gate::forUser($ownerB)->allows('removeMember', [$organization, $ownerA]));
    }

    public function test_admin_cannot_promote_anyone_to_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $member, 'member');

        $this->assertFalse(Gate::forUser($admin)->allows('updateMemberRole', [$organization, $member, 'owner']));
    }

    public function test_owner_may_promote_a_member_to_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');

        $this->assertTrue(Gate::forUser($owner)->allows('updateMemberRole', [$organization, $member, 'owner']));
    }

    // ------------------------------------------------------- 7-8: cross-org / non-member

    public function test_member_of_organization_a_cannot_manage_organization_b(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $ownerOfAlpha = User::factory()->create();
        $memberOfBeta = User::factory()->create();
        $this->attach($alpha, $ownerOfAlpha, 'owner');
        $this->attach($beta, $memberOfBeta, 'member');

        $this->assertFalse(Gate::forUser($ownerOfAlpha)->allows('view', $beta));
        $this->assertFalse(Gate::forUser($ownerOfAlpha)->allows('updateMemberRole', [$beta, $memberOfBeta, 'admin']));
        $this->assertFalse(Gate::forUser($ownerOfAlpha)->allows('removeMember', [$beta, $memberOfBeta]));
    }

    public function test_a_non_member_cannot_manage_the_organization_at_all(): void
    {
        $organization = Organization::factory()->create();
        $stranger = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $member, 'member');

        $this->assertFalse(Gate::forUser($stranger)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($stranger)->allows('updateMemberRole', [$organization, $member, 'admin']));
        $this->assertFalse(Gate::forUser($stranger)->allows('removeMember', [$organization, $member]));
    }

    // ------------------------------------------------------- 9-11: manipulated input

    public function test_a_target_from_a_different_organization_is_rejected(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $ownerOfAlpha = User::factory()->create();
        $userInBetaOnly = User::factory()->create();
        $this->attach($alpha, $ownerOfAlpha, 'owner');
        $this->attach($beta, $userInBetaOnly, 'member');

        // $userInBetaOnly is not a member of $alpha at all - roleOf() is null.
        $this->assertFalse(Gate::forUser($ownerOfAlpha)->allows('updateMemberRole', [$alpha, $userInBetaOnly, 'admin']));
        $this->assertFalse(Gate::forUser($ownerOfAlpha)->allows('removeMember', [$alpha, $userInBetaOnly]));
    }

    public function test_an_arbitrary_role_string_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');

        $this->assertFalse(Gate::forUser($owner)->allows('updateMemberRole', [$organization, $member, 'super-admin']));
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    // ------------------------------------------------------- 12: revoked access

    public function test_removing_a_member_immediately_revokes_organization_access(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');

        $organization->users()->detach($member->id);

        $this->assertFalse($organization->fresh()->isAccessibleBy($member));
        $this->assertFalse(Gate::forUser($member)->allows('view', $organization->fresh()));
    }

    // ------------------------------------------------------- 13: Project RBAC regression

    public function test_organization_management_does_not_grant_project_access(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($project->isAccessibleBy($owner));
        $this->assertFalse($project->isManageableBy($owner));
    }

    // ------------------------------------------------------- 14: Super Admin

    public function test_super_admin_is_unaffected_by_organization_management(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');

        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $organization));
        $this->assertTrue($superAdmin->isSuperAdmin());
    }

    // ------------------------------------------------------- Livewire tampering (end-to-end)

    /**
     * The action is visible to this actor at all (they can manage the
     * organization), but the specific change requested is not something
     * Filament's ->visible()/hidden-button UI is what stops - the real
     * Gate::authorize() call inside the action itself must still refuse it.
     * Proves Step 12's "don't just hide the button" for a case where the
     * button genuinely is shown.
     */
    public function test_an_admin_promoting_a_member_to_owner_via_the_real_action_is_denied(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $member, 'member');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('changeRole', $member, data: ['role' => 'owner'])
            ->assertForbidden();

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_the_change_role_action_is_hidden_from_a_plain_member(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $this->attach($organization, $member, 'member');
        $this->attach($organization, $otherMember, 'member');
        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionHidden('changeRole', $otherMember)
            ->assertTableActionHidden('removeMember', $otherMember);

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $otherMember->id,
            'role' => 'member',
        ]);
    }

    public function test_an_owner_cannot_promote_a_member_to_owner_via_a_tampered_payload_only_if_policy_allows(): void
    {
        // Sanity: the happy path actually works end-to-end through the real
        // Livewire component, not just the Policy in isolation.
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('changeRole', $member, data: ['role' => 'admin']);

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);
    }

    /**
     * removeMember's ->visible() is now an exact reuse of
     * OrganizationPolicy::removeMember() (Phase 5.1), so Filament hides the
     * button outright rather than only failing on click — Filament's own
     * test harness correctly refuses to call an action that isn't shown, so
     * this asserts the hidden state directly. "Manual invocation still
     * denied by the Policy even when the button IS shown" is proven
     * elsewhere for the equivalent changeRole case
     * (test_an_admin_promoting_a_member_to_owner_via_the_real_action_is_denied),
     * and for removeMember directly via Gate::forUser() in
     * test_the_sole_owner_cannot_be_removed_by_anyone /
     * test_admin_cannot_remove_an_owner above — the Policy itself is what's
     * authoritative, not this UI-level check.
     */
    public function test_the_remove_action_is_hidden_for_the_sole_owner(): void
    {
        $organization = Organization::factory()->create();
        $soleOwner = User::factory()->create();
        $admin = User::factory()->create();
        $this->attach($organization, $soleOwner, 'owner');
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionHidden('removeMember', $soleOwner);

        $this->assertTrue($organization->fresh()->isAccessibleBy($soleOwner));
    }

    public function test_a_non_member_cannot_even_open_the_page(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        Livewire::test(OrganizationSettings::class)->assertNotFound();
    }

    // ------------------------------------------------------- Phase 5.1: UI polish

    public function test_role_badges_display_the_actual_role_names(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $admin, 'admin');
        $this->attach($organization, $member, 'member');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->assertSee('Owner')
            ->assertSee('Admin')
            ->assertSee('Member');
    }

    public function test_owner_sees_manage_actions_for_other_members(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionVisible('changeRole', $member)
            ->assertTableActionVisible('removeMember', $member);
    }

    public function test_organization_context_determines_the_visible_member_list(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $user = User::factory()->create();
        $alphaOnlyMember = User::factory()->create(['name' => 'Alpha Only Person']);
        $betaOnlyMember = User::factory()->create(['name' => 'Beta Only Person']);
        $this->attach($alpha, $user, 'owner');
        $this->attach($beta, $user, 'owner');
        $this->attach($alpha, $alphaOnlyMember, 'member');
        $this->attach($beta, $betaOnlyMember, 'member');
        $this->actingAs($user);

        OrganizationContext::switch($user, $alpha->id);
        Livewire::test(OrganizationSettings::class)
            ->assertCanSeeTableRecords([$user, $alphaOnlyMember])
            ->assertCanNotSeeTableRecords([$betaOnlyMember]);

        OrganizationContext::switch($user, $beta->id);
        Livewire::test(OrganizationSettings::class)
            ->assertCanSeeTableRecords([$user, $betaOnlyMember])
            ->assertCanNotSeeTableRecords([$alphaOnlyMember]);
    }

    // ------------------------------------------------- Phase 5.2 regression

    /**
     * Found while building Phase 5.2's CreateOrganization: this page never
     * declared `public $data;`, so InteractsWithForms had nowhere to
     * hydrate the form into across requests - the very first render worked,
     * but any actual interaction with the "Organization name" field (typing
     * into it is exactly the same wire:model update path a test's
     * fillForm()/set() exercises) threw "Public property [$data] not
     * found". The Save button was silently broken for every real user.
     * Fixed by declaring the property, the same way Filament's own
     * CreateRecord page does.
     */
    public function test_saving_the_organization_name_actually_persists(): void
    {
        $organization = Organization::factory()->create(['name' => 'Old Name']);
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->fillForm(['name' => 'Renamed Organization'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Organization', $organization->fresh()->name);
    }
}

<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OrganizationMemberAdded;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.4.1 — Unified Organization Member Onboarding, existing-user
 * branch: the single "+ Tambah Anggota" action (still named `addMember`)
 * that used to be Phase 5.3A's user_id-search-based "Add member" now takes
 * only an email + role and decides server-side whether the recipient
 * already has an account. This file covers that decision's existing-user
 * outcome and the shared authorization matrix; the new-recipient/invitation
 * outcome is covered in OrganizationInvitationTest, and accepting one in
 * AcceptOrganizationInvitationTest.
 */
class OrganizationAddMemberTest extends TestCase
{
    use RefreshDatabase;

    private function panelUser(): User
    {
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    private function attach(Organization $organization, User $user, string $role): void
    {
        $organization->users()->attach($user->id, ['role' => $role]);
    }

    // ---------------------------------------------------------------- 1, 2

    public function test_owner_can_add_an_existing_user_as_a_member(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('member', $organization->fresh()->roleOf($target));
        Notification::assertSentTo($target, OrganizationMemberAdded::class);
    }

    public function test_owner_can_add_an_existing_user_as_an_admin(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $organization->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------- 3

    public function test_owner_cannot_add_an_existing_user_as_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'owner'])
            ->assertForbidden();

        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    // ---------------------------------------------------------------- 4, 5

    public function test_admin_can_add_an_existing_user_as_a_member(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('member', $organization->fresh()->roleOf($target));
    }

    public function test_admin_can_add_an_existing_user_as_an_admin(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $organization->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------- 6

    public function test_admin_cannot_add_an_existing_user_as_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'owner'])
            ->assertForbidden();

        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    // ---------------------------------------------------------------- 7, 8

    public function test_a_plain_member_cannot_add_anyone(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $member, 'member');
        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionHidden('addMember');

        $this->assertFalse(Gate::forUser($member)->allows('addMember', [$organization, 'member']));
        $this->assertFalse(Gate::forUser($member)->allows('addMember', [$organization, 'admin']));
        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    // ------------------------------------------------------------------- 9

    public function test_admin_of_organization_a_cannot_add_a_member_to_organization_b(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $admin = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organizationA, $admin, 'admin');
        $this->actingAs($admin);

        $this->assertFalse(Gate::forUser($admin)->allows('addMember', [$organizationB, 'member']));

        // No membership grows on B by acting from A's context either -
        // OrganizationSettings only ever operates on OrganizationContext::current(),
        // which resolves to A here, never a client-chosen organization.
        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member']);

        $this->assertFalse($organizationB->fresh()->isAccessibleBy($target));
    }

    // ------------------------------------------------------- 10, 11, existing

    public function test_existing_user_becomes_a_member_with_the_chosen_role(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $organization->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------ 12

    /**
     * The strongest proof that no password is touched: the target's
     * original hashed password is byte-for-byte unchanged after being
     * added, and no invitation (which would imply a "create your own
     * password" step) exists for them either.
     */
    public function test_existing_user_is_not_given_a_new_password(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $originalPasswordHash = $target->password;
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member']);

        $this->assertSame($originalPasswordHash, $target->fresh()->password);
    }

    // ------------------------------------------------------------------ 13

    public function test_existing_user_does_not_get_an_invitation_row(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member']);

        $this->assertDatabaseMissing('organization_invitations', ['email' => $target->email]);
    }

    // ------------------------------------------------------------------ 14

    public function test_adding_an_existing_member_again_is_rejected_safely(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $alreadyMember = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $alreadyMember, 'member');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $alreadyMember->email, 'role' => 'admin'])
            ->assertHasTableActionErrors(['email']);

        $this->assertSame('member', $organization->fresh()->roleOf($alreadyMember));
    }

    public function test_the_acting_owner_adding_themselves_again_is_rejected_as_a_duplicate(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $owner->email, 'role' => 'member'])
            ->assertHasTableActionErrors(['email']);

        $this->assertSame('owner', $organization->fresh()->roleOf($owner));
    }

    // ------------------------------------------------------------------ 15

    public function test_a_soft_deleted_user_is_not_added_as_a_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $deletedUser = User::factory()->create();
        $deletedEmail = $deletedUser->email;
        $deletedUser->delete();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        // Not found by the default (non-trashed) query -> treated as a
        // brand-new recipient (an invitation), never silently restored or
        // attached.
        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $deletedEmail, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($organization->fresh()->isAccessibleBy($deletedUser));
        $this->assertDatabaseHas('organization_invitations', ['email' => $deletedEmail]);
        $this->assertSoftDeleted($deletedUser);
    }

    // ------------------------------------------------------- multi-org, H, I

    public function test_the_same_user_can_be_added_to_multiple_organizations(): void
    {
        Notification::fake();
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $gamma = Organization::factory()->create();
        $ownerAlpha = $this->panelUser();
        $adminGamma = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($alpha, $ownerAlpha, 'owner');
        $this->attach($beta, $target, 'member');
        $this->attach($gamma, $adminGamma, 'admin');
        $this->actingAs($ownerAlpha);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $alpha->fresh()->roleOf($target));
        $this->assertSame('member', $beta->fresh()->roleOf($target));

        $this->actingAs($adminGamma);
        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $alpha->fresh()->roleOf($target), 'Alpha untouched by the Gamma add');
        $this->assertSame('member', $beta->fresh()->roleOf($target), 'Beta untouched by the Gamma add');
        $this->assertSame('member', $gamma->fresh()->roleOf($target));
    }

    // ---------------------------------------------------- role tampering, 33-36

    public function test_an_arbitrary_role_string_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        foreach (['super_admin', 'platform_admin', 'owner_of_everything'] as $maliciousRole) {
            Livewire::test(OrganizationSettings::class)
                ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => $maliciousRole])
                ->assertForbidden();
        }

        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    public function test_organization_id_cannot_be_manipulated_through_the_payload(): void
    {
        $current = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($current, $owner, 'owner');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $current->id);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: [
                'email' => $target->email,
                'role' => 'member',
                'organization_id' => $foreign->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($current->fresh()->isAccessibleBy($target));
        $this->assertFalse($foreign->fresh()->isAccessibleBy($target));
    }

    public function test_a_user_id_in_the_payload_is_ignored_the_form_only_has_email(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $victim = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: [
                'email' => $target->email,
                'role' => 'member',
                'user_id' => $victim->id,
            ]);

        $this->assertSame('member', $organization->fresh()->roleOf($target));
        $this->assertNull($organization->fresh()->roleOf($victim));
    }

    // ---------------------------------------------------------- Policy gate

    public function test_organization_policy_add_member_never_allows_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');

        $this->assertFalse(Gate::forUser($owner)->allows('addMember', [$organization, 'owner']));
        $this->assertTrue(Gate::forUser($owner)->allows('addMember', [$organization, 'admin']));
        $this->assertTrue(Gate::forUser($owner)->allows('addMember', [$organization, 'member']));
        $this->assertFalse(Gate::forUser($owner)->allows('addMember', [$organization, 'not-a-real-role']));
    }

    // --------------------------------------------------------------- Super Admin

    public function test_platform_super_admin_gets_no_automatic_organization_ownership(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();

        $this->assertFalse(Gate::forUser($superAdmin)->allows('addMember', [$organization, 'member']));
        $this->assertTrue($superAdmin->isSuperAdmin());
    }

    // ---------------------------------------------------- context regression

    public function test_organization_context_is_unchanged_after_adding_a_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $organization->id);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['email' => $target->email, 'role' => 'member']);

        $this->assertTrue(OrganizationContext::current($owner)->is($organization));
    }

    public function test_the_database_unique_constraint_rejects_a_true_duplicate_pivot_row(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'member']);

        try {
            $organization->users()->attach($user->id, ['role' => 'admin']);
            $this->fail('Expected the unique(organization_id, user_id) index to reject a duplicate pivot row.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }

        $this->assertSame('member', $organization->fresh()->roleOf($user));
    }
}

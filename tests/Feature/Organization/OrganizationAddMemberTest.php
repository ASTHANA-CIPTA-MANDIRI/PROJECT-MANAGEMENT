<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.3A — Add Existing User to Organization. Covers the brief's A-U
 * list directly against the real Livewire header action (not just
 * OrganizationPolicy::addMember() in isolation), since the whole point of
 * this phase is that the target user, role, and organization are all
 * resolved/validated server-side, never trusted from the submitted payload.
 */
class OrganizationAddMemberTest extends TestCase
{
    use RefreshDatabase;

    private function attach(Organization $organization, User $user, string $role): void
    {
        $organization->users()->attach($user->id, ['role' => $role]);
    }

    // ---------------------------------------------------------------- A, B

    public function test_owner_can_add_an_existing_user_as_a_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('member', $organization->fresh()->roleOf($target));
        $this->assertSame('owner', $organization->fresh()->roleOf($owner));
    }

    public function test_owner_can_add_an_existing_user_as_an_admin(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $organization->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------- C

    public function test_a_plain_member_cannot_add_anyone(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $member, 'member');
        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionHidden('addMember');

        // Not just a hidden button - the real gate refuses it too.
        $this->assertFalse(Gate::forUser($member)->allows('addMember', [$organization, 'member']));
        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    // ------------------------------------------------------------------- D

    public function test_organization_id_cannot_be_manipulated_through_the_payload(): void
    {
        $current = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($current, $owner, 'owner');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $current->id);

        // There is no organization_id field on this form at all - the
        // action always writes to $this->organization() (OrganizationContext),
        // never to a client-supplied id, even when one is stuffed into the
        // payload alongside the real fields.
        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: [
                'user_id' => $target->id,
                'role' => 'member',
                'organization_id' => $foreign->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($current->fresh()->isAccessibleBy($target));
        $this->assertFalse($foreign->fresh()->isAccessibleBy($target));
    }

    // ---------------------------------------------------------------- E, F

    public function test_an_arbitrary_or_platform_level_role_string_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        foreach (['super_admin', 'platform_admin', 'owner_of_everything'] as $maliciousRole) {
            Livewire::test(OrganizationSettings::class)
                ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => $maliciousRole])
                ->assertForbidden();
        }

        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    // ------------------------------------------------------------------- G

    public function test_adding_an_existing_member_again_is_rejected_safely(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $alreadyMember = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $alreadyMember, 'member');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $alreadyMember->id, 'role' => 'admin'])
            ->assertHasTableActionErrors(['user_id']);

        // Rejected safely - no promotion happened as a side effect either.
        $this->assertSame('member', $organization->fresh()->roleOf($alreadyMember));
    }

    /**
     * The "self add" case called out explicitly in the brief: the acting
     * Owner is themselves already a member, and must be treated as a
     * duplicate exactly like any other already-a-member target.
     */
    public function test_the_acting_owner_adding_themselves_again_is_rejected_as_a_duplicate(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $owner->id, 'role' => 'member'])
            ->assertHasTableActionErrors(['user_id']);

        $this->assertSame('owner', $organization->fresh()->roleOf($owner));
    }

    // ---------------------------------------------------------------- H, I, K

    public function test_the_same_user_can_belong_to_multiple_organizations_without_the_other_membership_changing(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $owner = User::factory()->create();
        // $target already belongs to Beta as Admin - a cross-organization
        // add into Alpha must not touch that membership at all.
        $target = User::factory()->create();
        $this->attach($alpha, $owner, 'owner');
        $this->attach($beta, $owner, 'owner');
        $this->attach($beta, $target, 'admin');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $alpha->id);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('member', $alpha->fresh()->roleOf($target));
        $this->assertSame('admin', $beta->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------- J

    public function test_a_user_with_zero_organizations_can_be_added(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $noOrgUser = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        $this->assertSame(0, $noOrgUser->organizations()->count());

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $noOrgUser->id, 'role' => 'member'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('member', $organization->fresh()->roleOf($noOrgUser));
    }

    // ------------------------------------------------------------------- L

    public function test_a_soft_deleted_user_cannot_be_added(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $deletedUser = User::factory()->create();
        $deletedUser->delete();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $deletedUser->id, 'role' => 'member'])
            ->assertHasTableActionErrors(['user_id']);

        $this->assertFalse($organization->fresh()->isAccessibleBy($deletedUser));
    }

    public function test_search_excludes_soft_deleted_users(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $deletedUser = User::factory()->create(['name' => 'Ghost Person']);
        $deletedUser->delete();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        $component = Livewire::test(OrganizationSettings::class);
        $component->mountTableAction('addMember');
        $field = $component->instance()->getMountedTableActionForm()->getFlatFields()['user_id'];

        $this->assertArrayNotHasKey($deletedUser->id, $field->getSearchResults('Ghost'));
    }

    // ------------------------------------------------------------------- M

    public function test_search_only_exposes_name_and_email(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create([
            'name' => 'Searchable Target',
            'email' => 'searchable-target@example.test',
        ]);
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        $component = Livewire::test(OrganizationSettings::class);
        $component->mountTableAction('addMember');
        $field = $component->instance()->getMountedTableActionForm()->getFlatFields()['user_id'];

        $results = $field->getSearchResults('Searchable');

        $this->assertSame(
            ['Searchable Target (searchable-target@example.test)'],
            array_values($results)
        );
    }

    public function test_search_excludes_existing_members_of_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $existingMember = User::factory()->create(['name' => 'Already Here']);
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $existingMember, 'member');
        $this->actingAs($owner);

        $component = Livewire::test(OrganizationSettings::class);
        $component->mountTableAction('addMember');
        $field = $component->instance()->getMountedTableActionForm()->getFlatFields()['user_id'];

        $this->assertArrayNotHasKey($existingMember->id, $field->getSearchResults('Already'));
    }

    public function test_search_includes_a_user_who_belongs_to_a_different_organization(): void
    {
        $current = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create();
        $foreignMember = User::factory()->create(['name' => 'Foreign Member']);
        $this->attach($current, $owner, 'owner');
        $this->attach($other, $foreignMember, 'member');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $current->id);

        $component = Livewire::test(OrganizationSettings::class);
        $component->mountTableAction('addMember');
        $field = $component->instance()->getMountedTableActionForm()->getFlatFields()['user_id'];

        $this->assertArrayHasKey($foreignMember->id, $field->getSearchResults('Foreign'));
    }

    // ------------------------------------------------------------------- O

    public function test_admin_cannot_add_a_new_member_as_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'owner'])
            ->assertForbidden();

        $this->assertFalse($organization->fresh()->isAccessibleBy($target));
    }

    public function test_admin_can_add_a_new_member_as_member_or_admin(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $admin, 'admin');
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'admin'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $organization->fresh()->roleOf($target));
    }

    public function test_owner_can_add_a_new_member_directly_as_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'owner'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('owner', $organization->fresh()->roleOf($target));
    }

    // ------------------------------------------------------------------- P

    public function test_platform_super_admin_gets_no_automatic_organization_ownership(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();

        $this->assertFalse(Gate::forUser($superAdmin)->allows('addMember', [$organization, 'member']));
        $this->assertTrue($superAdmin->isSuperAdmin());
    }

    // ------------------------------------------------------------------- T

    public function test_organization_context_is_unchanged_after_adding_a_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->actingAs($owner);
        OrganizationContext::switch($owner, $organization->id);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['user_id' => $target->id, 'role' => 'member']);

        $this->assertTrue(OrganizationContext::current($owner)->is($organization));
    }

    // ------------------------------------------------------------------- U

    /**
     * Verified directly against the schema, independent of the application
     * code above: the unique(organization_id, user_id) index has existed
     * since Phase 2 (2026_09_02_000002_create_organization_users_table.php)
     * and is created identically by Schema::unique() for both the SQLite
     * connection this suite runs on and MySQL in production - not a
     * SQLite-only guarantee.
     */
    public function test_the_database_unique_constraint_rejects_a_true_duplicate_pivot_row(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'member']);

        try {
            $organization->users()->attach($user->id, ['role' => 'admin']);
            $this->fail('Expected the unique(organization_id, user_id) index to reject a duplicate pivot row.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }

        $this->assertSame('member', $organization->fresh()->roleOf($user));
    }

    // ---------------------------------------------------------- Policy gate

    public function test_organization_policy_add_member_is_the_actual_gate(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attach($organization, $owner, 'owner');

        $this->assertTrue(Gate::forUser($owner)->allows('addMember', [$organization, 'member']));
        $this->assertFalse(Gate::forUser($owner)->allows('addMember', [$organization, 'not-a-real-role']));
    }
}

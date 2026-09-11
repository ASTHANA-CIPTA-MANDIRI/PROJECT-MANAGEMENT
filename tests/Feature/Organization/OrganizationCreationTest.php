<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\CreateOrganization;
use App\Models\Activity;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\EmployeeRoleSeeder;
use Database\Seeders\OrganizationDemoSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.2 — Create Organization + Initial Owner. Covers the brief's A-L
 * security/functional list directly against the real Livewire component,
 * not just OrganizationPolicy in isolation, since the whole point of this
 * phase is that the creator (never a client-supplied id) becomes Owner
 * through one atomic transaction.
 */
class OrganizationCreationTest extends TestCase
{
    use RefreshDatabase;

    private function panelUser(): User
    {
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    // ------------------------------------------------------------- A, B

    public function test_authenticated_user_can_create_an_organization_and_becomes_owner(): void
    {
        $user = $this->panelUser();
        $this->actingAs($user);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'Fresh Organization'])
            ->call('create')
            ->assertHasNoFormErrors();

        $organization = Organization::where('name', 'Fresh Organization')->firstOrFail();

        $this->assertSame('owner', $organization->roleOf($user));
    }

    /**
     * Fase 3B — the manual creation path gets the same reference-data
     * starter set as auto-provisioning (App\Support\OrganizationDefaults is
     * the single call site both share).
     */
    public function test_creating_an_organization_seeds_its_own_activity_starter_set(): void
    {
        $user = $this->panelUser();
        $this->actingAs($user);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'Fresh Organization'])
            ->call('create')
            ->assertHasNoFormErrors();

        $organization = Organization::where('name', 'Fresh Organization')->firstOrFail();

        $this->assertSame(
            count(ActivitySeeder::defaults()),
            Activity::where('organization_id', $organization->id)->count()
        );
    }

    // ---------------------------------------------------------------- C

    public function test_a_failure_creating_the_membership_rolls_back_the_organization(): void
    {
        $user = $this->panelUser();
        $this->actingAs($user);

        // A real Eloquent event, not a mock of internals: fires right after
        // Organization::create() succeeds, before the membership attach
        // runs, proving DB::transaction actually rolls the first write back
        // when the second one fails - not just that the code looks atomic.
        Event::listen('eloquent.created: '.Organization::class, function () {
            throw new \RuntimeException('Simulated membership failure');
        });

        try {
            Livewire::test(CreateOrganization::class)
                ->fillForm(['name' => 'Should Not Persist'])
                ->call('create');
        } catch (\RuntimeException $exception) {
            // Expected - the point is what happened to the database, not
            // whether the exception was caught gracefully.
        }

        $this->assertDatabaseMissing('organizations', ['name' => 'Should Not Persist']);
    }

    // ---------------------------------------------------------------- D

    public function test_duplicate_organization_name_is_rejected_safely(): void
    {
        $user = $this->panelUser();
        Organization::factory()->create(['name' => 'Taken Name']);
        $this->actingAs($user);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'Taken Name'])
            ->call('create')
            ->assertHasErrors(['data.name']);

        $this->assertSame(1, Organization::where('name', 'Taken Name')->count());
        $this->assertFalse(Organization::where('name', 'Taken Name')->first()->isAccessibleBy($user));
    }

    // ---------------------------------------------------------------- E

    public function test_empty_or_whitespace_only_name_is_rejected(): void
    {
        // Caught by the field's own `required` rule (Laravel's required
        // validator already treats a whitespace-only string as empty) -
        // CreateOrganization::create()'s own trim()-based blank check is a
        // defensive, explicit backstop for the same rule, not the only
        // thing standing between this input and a row with a blank name.
        $user = $this->panelUser();
        $this->actingAs($user);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => '   '])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(0, Organization::count());
    }

    // ---------------------------------------------------------------- F

    public function test_unauthenticated_user_cannot_create_an_organization(): void
    {
        // A guest hitting the page at all is stopped by the panel's own
        // authentication middleware before the Livewire component ever
        // mounts - tested at the HTTP level rather than via Livewire::test()
        // (which bypasses routing/middleware and mounts the component
        // directly, not a faithful simulation of "never logged in" here).
        $this->get(route('filament.pages.create-organization'))
            ->assertRedirect();

        $this->assertSame(0, Organization::count());
    }

    // ------------------------------------------------------------- G, H

    public function test_creator_is_always_the_authenticated_user_never_a_supplied_id(): void
    {
        $actor = $this->panelUser();
        $bystander = User::factory()->create();
        $this->actingAs($actor);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'Owned By Actor'])
            ->call('create');

        $organization = Organization::where('name', 'Owned By Actor')->firstOrFail();

        $this->assertSame('owner', $organization->roleOf($actor));
        $this->assertNull($organization->roleOf($bystander));
    }

    public function test_an_existing_organization_cannot_be_hijacked_through_this_flow(): void
    {
        $user = $this->panelUser();
        $existing = Organization::factory()->create(['name' => 'Untouchable']);
        $existingOwner = User::factory()->create();
        $existing->users()->attach($existingOwner->id, ['role' => 'owner']);
        $this->actingAs($user);

        // There is no organization_id field on this form at all - the
        // create() action never reads one from the request. This proves
        // the outcome (the existing organization is untouched) rather than
        // just trusting the absence of a field.
        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'A Brand New Name'])
            ->call('create');

        $this->assertSame('owner', $existing->fresh()->roleOf($existingOwner));
        $this->assertNull($existing->fresh()->roleOf($user));
    }

    // ---------------------------------------------------------------- I

    /**
     * Phase 5.4.4F (Option B), CASE 5 of the 5.4.4E audit: a multi-org user
     * (Owner of two organizations, Member of a third) is no longer allowed
     * to create yet another one - "zero memberships" now means zero, not
     * "regardless of how many organizations you already have." Previously
     * this asserted the opposite (successful creation with existing
     * memberships left untouched); the untouched-memberships guarantee is
     * still proven here, just via denial leaving nothing to touch at all.
     */
    public function test_multi_organization_user_cannot_create_another_organization(): void
    {
        $this->seed(PermissionsSeeder::class);
        $this->seed(EmployeeRoleSeeder::class);
        $this->seed(OrganizationDemoSeeder::class);

        $multi = User::where('email', 'multi@example.test')->firstOrFail();
        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        $beta = Organization::where('name', 'Organization Beta')->firstOrFail();
        $gamma = Organization::where('name', 'Organization Gamma')->firstOrFail();

        $this->actingAs($multi);

        Livewire::test(CreateOrganization::class)->assertForbidden();

        $this->assertDatabaseMissing('organizations', ['name' => 'Organization Epsilon']);
        // Untouched, exactly as documented by OrganizationDemoSeeder.
        $this->assertSame('member', $alpha->fresh()->roleOf($multi));
        $this->assertSame('owner', $beta->fresh()->roleOf($multi));
        $this->assertSame('owner', $gamma->fresh()->roleOf($multi));
    }

    // ---------------------------------------------------------------- J

    public function test_current_organization_context_switches_to_the_new_organization(): void
    {
        // Phase 5.4.4F (Option B): the actor must have zero organization
        // memberships to be allowed to create at all, so this test no
        // longer pre-attaches them to an unrelated organization first -
        // its purpose (verifying OrganizationContext::switch() after a
        // successful create) is unaffected by that setup change.
        $user = $this->panelUser();
        $this->actingAs($user);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'New Current Organization'])
            ->call('create');

        $newOrganization = Organization::where('name', 'New Current Organization')->firstOrFail();

        $this->assertTrue(OrganizationContext::current($user)->is($newOrganization));
    }

    // ---------------------------------------------------------------- L

    public function test_a_user_with_zero_organizations_can_create_one_and_becomes_owner(): void
    {
        $this->seed(PermissionsSeeder::class);
        $this->seed(EmployeeRoleSeeder::class);
        $this->seed(OrganizationDemoSeeder::class);

        $noOrg = User::where('email', 'noorg@example.test')->firstOrFail();
        $this->assertSame(0, $noOrg->organizations()->count());
        $this->actingAs($noOrg);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['name' => 'noorg First Organization'])
            ->call('create')
            ->assertHasNoFormErrors();

        $organization = Organization::where('name', 'noorg First Organization')->firstOrFail();

        $this->assertSame('owner', $organization->roleOf($noOrg));
        $this->assertTrue(OrganizationContext::current($noOrg)->is($organization));
    }

    // ---------------------------------------------------------- Policy gate

    public function test_organization_policy_create_is_the_actual_gate(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('create', Organization::class));
    }

    // -------------------------------------- Phase 5.4.4F — Option B, CASE 2-8

    /**
     * CASE 2 of the 5.4.4E audit: an Organization Owner may manage the
     * organization they own, but that authority does not extend to
     * spinning up a second, unrelated one.
     */
    public function test_user_already_owning_an_organization_cannot_create_another(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));

        $this->actingAs($user);
        Livewire::test(CreateOrganization::class)->assertForbidden();
    }

    /**
     * CASE 3 of the 5.4.4E audit: same rule for an Admin as for an Owner -
     * Option B is membership-count-based, not role-based, so it does not
     * matter which manageable role the user already holds.
     */
    public function test_an_organization_admin_cannot_create_another_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'admin']);

        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));
    }

    /**
     * CASE 4 of the 5.4.4E audit — the "after" version of the baseline this
     * phase captured before OrganizationPolicy::create() changed (a pure
     * Member, never Owner/Admin anywhere, was the one persona the audit
     * found no isolated test for at all).
     */
    public function test_a_pure_member_without_any_owner_or_admin_role_cannot_create_another_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'member']);

        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));

        $this->actingAs($user);
        Livewire::test(CreateOrganization::class)->assertForbidden();
    }

    /**
     * CASE 6 of the 5.4.4E audit: being a plain Member of two organizations
     * is still one-or-more memberships - the rule is "zero or not zero",
     * never a count/role distinction.
     */
    public function test_a_member_of_two_organizations_cannot_create_another_organization(): void
    {
        $user = User::factory()->create();
        Organization::factory()->create()->users()->attach($user->id, ['role' => 'member']);
        Organization::factory()->create()->users()->attach($user->id, ['role' => 'member']);

        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));
    }

    /**
     * CASE 7 of the 5.4.4E audit: Platform Super Admin gets no special
     * treatment in either direction - OrganizationPolicy::create() never
     * inspects Spatie roles at all, so a Super Admin with zero organization
     * memberships is allowed through exactly like anyone else in the same
     * position. This is a regression guard against ever adding a Super
     * Admin bypass here (hard rule for this phase).
     */
    public function test_super_admin_without_an_organization_can_still_create_one(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', Organization::class));
    }

    /**
     * CASE 8 of the 5.4.4E audit: Super Admin status does not exempt a user
     * from Option B either - once they hold any organization membership
     * (here, Owner), the same "zero memberships only" rule applies with no
     * bypass, exactly as CASE 7's sibling proves the absence of a bypass in
     * the other direction.
     */
    public function test_super_admin_who_already_owns_an_organization_cannot_create_another(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);
        $organization = Organization::factory()->create();
        $organization->users()->attach($superAdmin->id, ['role' => 'owner']);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('create', Organization::class));
    }
}

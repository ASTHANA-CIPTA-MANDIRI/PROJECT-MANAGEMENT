<?php

namespace Tests\Feature\Organization;

use App\Http\Livewire\OrganizationSwitcher;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3C — the UI half of OrganizationContext (Phase 3A). The Security
 * Attack Matrix from the brief, exercised through the real Livewire
 * component rather than the underlying service alone (OrganizationContextTest
 * already covers that).
 */
class OrganizationSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_to_a_member_organization_succeeds(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user);
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->call('switchOrganization', $organization->id)
            ->assertRedirect();

        $this->assertTrue(OrganizationContext::current($user)->is($organization));
    }

    public function test_switching_to_a_foreign_organization_is_denied(): void
    {
        $ownOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $ownOrganization->users()->attach($user);
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->call('switchOrganization', $foreignOrganization->id)
            ->assertNoRedirect();

        $this->assertTrue(OrganizationContext::current($user)->is($ownOrganization));
    }

    public function test_a_stale_session_selection_is_dropped_once_membership_is_revoked(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user);
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->call('switchOrganization', $organization->id)
            ->assertRedirect();

        $organization->users()->detach($user);

        $this->assertNull(OrganizationContext::current($user));
    }

    public function test_a_user_without_any_organization_sees_no_selector(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->assertDontSeeHtml('<select')
            ->assertDontSee('Organization');

        $this->assertNull(OrganizationContext::current($user));
    }

    public function test_a_user_with_a_single_organization_sees_a_static_label_not_a_dropdown(): void
    {
        $organization = Organization::factory()->create(['name' => 'Only Org']);
        $user = User::factory()->create();
        $organization->users()->attach($user);
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->assertSee('Only Org')
            ->assertDontSeeHtml('<select');

        $this->assertTrue(OrganizationContext::current($user)->is($organization));
    }

    public function test_a_multi_org_user_sees_a_dropdown_listing_both(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Org A']);
        $orgB = Organization::factory()->create(['name' => 'Org B']);
        $user = User::factory()->create();
        $orgA->users()->attach($user);
        $orgB->users()->attach($user);
        $this->actingAs($user);

        Livewire::test(OrganizationSwitcher::class)
            ->assertSeeHtml('<select')
            ->assertSee('Org A')
            ->assertSee('Org B');
    }

    public function test_switching_between_two_memberships_flips_project_accessibility_each_time(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $orgA->users()->attach($user);
        $orgB->users()->attach($user);

        $projectA = Project::factory()->create(['organization_id' => $orgA->id]);
        $projectA->users()->attach($user->id, ['role' => 'employee']);
        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);
        $projectB->users()->attach($user->id, ['role' => 'employee']);

        $this->actingAs($user);
        $component = Livewire::test(OrganizationSwitcher::class);

        $component->call('switchOrganization', $orgA->id);
        $this->assertTrue($projectA->fresh()->isAccessibleBy($user));
        $this->assertFalse($projectB->fresh()->isAccessibleBy($user));

        $component->call('switchOrganization', $orgB->id);
        $this->assertFalse($projectA->fresh()->isAccessibleBy($user));
        $this->assertTrue($projectB->fresh()->isAccessibleBy($user));

        $component->call('switchOrganization', $orgA->id);
        $this->assertTrue($projectA->fresh()->isAccessibleBy($user));
        $this->assertFalse($projectB->fresh()->isAccessibleBy($user));
    }

    public function test_a_super_admin_with_no_organization_membership_sees_no_selector(): void
    {
        // No blanket Organization bypass for Super Admin (Phase 3A/3B
        // precedent) — the same "0 organizations -> hidden" rule applies.
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);
        $this->actingAs($superAdmin);

        Livewire::test(OrganizationSwitcher::class)
            ->assertDontSeeHtml('<select');

        $this->assertNull(OrganizationContext::current($superAdmin));
    }

    public function test_tampering_with_the_livewire_payload_cannot_select_a_foreign_organization(): void
    {
        $ownOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $ownOrganization->users()->attach($user);
        $this->actingAs($user);

        // set() simulates a raw client-controlled payload the same way a
        // crafted request could, independent of what the rendered <select>
        // actually offers.
        Livewire::test(OrganizationSwitcher::class)
            ->call('switchOrganization', $foreignOrganization->id);

        $this->assertTrue(OrganizationContext::current($user)->is($ownOrganization));
    }
}

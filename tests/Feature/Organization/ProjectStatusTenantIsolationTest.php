<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Fase 3B — fourth model isolated, same pattern as
 * Tests\Feature\Organization\TicketTypeTenantIsolationTest, including the
 * same is_default-per-Organization coverage.
 */
class ProjectStatusTenantIsolationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function memberOf(Organization $organization): User
    {
        $user = $this->userWithPermissions([
            'View project status', 'Update project status', 'Delete project status', 'Create project status',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    // ------------------------------------------------------------- scope

    public function test_a_user_does_not_see_another_organizations_project_status(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = ProjectStatus::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse(ProjectStatus::visibleTo($user)->whereKey($foreign->id)->exists());
    }

    public function test_a_legacy_project_status_with_no_organization_is_visible_to_everyone(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $legacy = ProjectStatus::factory()->create(['organization_id' => null]);

        $this->assertTrue(ProjectStatus::visibleTo($user)->whereKey($legacy->id)->exists());
    }

    // -------------------------------------------------------------- policy

    public function test_a_user_cannot_view_another_organizations_project_status(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = ProjectStatus::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse($user->can('view', $foreign));
        $this->assertFalse($user->can('update', $foreign));
        $this->assertFalse($user->can('delete', $foreign));
    }

    // ------------------------------------------------------------ observer

    public function test_creating_a_project_status_stamps_the_users_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $this->actingAs($user);
        $status = ProjectStatus::create(['name' => 'Blocked', 'color' => '#000000', 'is_default' => false]);

        $this->assertSame($organization->id, $status->organization_id);
    }

    /**
     * The critical Fase 3B behavior ProjectStatusObserver needed: setting a
     * default in one Organization must never un-default another
     * Organization's default. Before this fix, ProjectStatusObserver::saved()
     * unset is_default on every OTHER row in the entire table, with no
     * organization_id scoping at all.
     */
    public function test_setting_a_default_in_one_organization_does_not_unset_another_organizations_default(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $defaultA = ProjectStatus::factory()->create(['organization_id' => $organizationA->id, 'is_default' => true]);
        $defaultB = ProjectStatus::factory()->create(['organization_id' => $organizationB->id, 'is_default' => true]);

        // Save org A's default again (e.g. an edit that keeps is_default=true) -
        // this must not touch org B's default at all.
        $defaultA->update(['name' => 'Renamed Status']);

        // ProjectStatus.is_default has no boolean cast (pre-existing,
        // unrelated to Fase 3B) - SQLite hands back 0/1, so compare loosely
        // rather than with assertTrue()'s strict boolean check.
        $this->assertEquals(1, $defaultA->fresh()->is_default);
        $this->assertEquals(1, $defaultB->fresh()->is_default, "org B's default must survive org A's save");
    }

    public function test_setting_a_new_default_unsets_the_previous_one_within_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $oldDefault = ProjectStatus::factory()->create(['organization_id' => $organization->id, 'is_default' => true]);
        $newDefault = ProjectStatus::factory()->create(['organization_id' => $organization->id, 'is_default' => false]);

        $newDefault->update(['is_default' => true]);

        $this->assertEquals(0, $oldDefault->fresh()->is_default);
        $this->assertEquals(1, $newDefault->fresh()->is_default);
    }

    // -------------------------------------------------------------- seeder

    public function test_seed_for_creates_the_full_starter_set_scoped_to_the_organization(): void
    {
        $organization = Organization::factory()->create();

        ProjectStatusSeeder::seedFor($organization);

        $this->assertSame(count(ProjectStatusSeeder::defaults()), ProjectStatus::where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('project_statuses', ['organization_id' => $organization->id, 'name' => 'Not started', 'is_default' => true]);
    }
}

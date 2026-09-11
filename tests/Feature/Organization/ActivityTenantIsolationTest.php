<?php

namespace Tests\Feature\Organization;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\ActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Fase 3B — Activity is the reference implementation for the 5-table
 * tenant-isolation work (App\Models\Concerns\BelongsToOrganization). These
 * tests pin down the pattern every other lookup table (TicketType,
 * TicketPriority, Label, ProjectStatus) replicates.
 */
class ActivityTenantIsolationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function memberOf(Organization $organization): User
    {
        $user = $this->userWithPermissions([
            'View activity', 'Update activity', 'Delete activity', 'Create activity',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    // ------------------------------------------------------------- scope

    public function test_a_user_sees_their_own_organizations_activities(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $activity = Activity::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue(Activity::visibleTo($user)->whereKey($activity->id)->exists());
    }

    public function test_a_user_does_not_see_another_organizations_activity(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreignActivity = Activity::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse(Activity::visibleTo($user)->whereKey($foreignActivity->id)->exists());
    }

    public function test_a_legacy_activity_with_no_organization_is_visible_to_everyone(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $legacyActivity = Activity::factory()->create(['organization_id' => null]);

        $this->assertTrue(Activity::visibleTo($user)->whereKey($legacyActivity->id)->exists());
    }

    // -------------------------------------------------------------- policy

    public function test_a_user_can_view_their_own_organizations_activity(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $activity = Activity::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('view', $activity));
    }

    public function test_a_user_cannot_view_another_organizations_activity_even_with_the_permission(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreignActivity = Activity::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse($user->can('view', $foreignActivity));
        $this->assertFalse($user->can('update', $foreignActivity));
        $this->assertFalse($user->can('delete', $foreignActivity));
    }

    // ------------------------------------------------------------ observer

    public function test_creating_an_activity_stamps_the_users_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $this->actingAs($user);
        $activity = Activity::create(['name' => 'Design', 'description' => 'Design work']);

        $this->assertSame($organization->id, $activity->organization_id);
    }

    public function test_creating_an_activity_with_no_authenticated_user_leaves_organization_id_null(): void
    {
        $activity = Activity::factory()->create();

        // Factory-created rows never set organization_id explicitly, so this
        // proves the observer does not fire outside an authenticated context
        // (e.g. seeders, factories, console) - the same guarantee
        // ProjectObserver::creating() gives Project.
        $this->assertNull($activity->organization_id);
    }

    // -------------------------------------------------------------- seeder

    public function test_seed_for_creates_the_full_starter_set_scoped_to_the_organization(): void
    {
        $organization = Organization::factory()->create();

        ActivitySeeder::seedFor($organization);

        $this->assertSame(count(ActivitySeeder::defaults()), Activity::where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('activities', ['organization_id' => $organization->id, 'name' => 'Programming']);
    }

    public function test_two_organizations_each_get_their_own_independent_copy(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        ActivitySeeder::seedFor($organizationA);
        ActivitySeeder::seedFor($organizationB);

        $this->assertSame(count(ActivitySeeder::defaults()), Activity::where('organization_id', $organizationA->id)->count());
        $this->assertSame(count(ActivitySeeder::defaults()), Activity::where('organization_id', $organizationB->id)->count());
    }
}

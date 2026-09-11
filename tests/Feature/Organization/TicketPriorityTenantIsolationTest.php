<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\TicketPriority;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\TicketPrioritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Fase 3B — same pattern as
 * Tests\Feature\Organization\TicketTypeTenantIsolationTest, TicketPriority's
 * twin in every structural way (organization_id, is_default + Observer).
 */
class TicketPriorityTenantIsolationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function memberOf(Organization $organization): User
    {
        $user = $this->userWithPermissions([
            'View ticket priority', 'Update ticket priority', 'Delete ticket priority', 'Create ticket priority',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    public function test_a_user_does_not_see_another_organizations_ticket_priority(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = TicketPriority::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse(TicketPriority::visibleTo($user)->whereKey($foreign->id)->exists());
    }

    public function test_a_legacy_ticket_priority_with_no_organization_is_visible_to_everyone(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $legacy = TicketPriority::factory()->create(['organization_id' => null]);

        $this->assertTrue(TicketPriority::visibleTo($user)->whereKey($legacy->id)->exists());
    }

    public function test_a_user_cannot_view_another_organizations_ticket_priority(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = TicketPriority::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse($user->can('view', $foreign));
        $this->assertFalse($user->can('update', $foreign));
        $this->assertFalse($user->can('delete', $foreign));
    }

    public function test_creating_a_ticket_priority_stamps_the_users_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $this->actingAs($user);
        $priority = TicketPriority::create(['name' => 'Urgent', 'is_default' => false]);

        $this->assertSame($organization->id, $priority->organization_id);
    }

    public function test_setting_a_default_in_one_organization_does_not_unset_another_organizations_default(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $defaultA = TicketPriority::factory()->create(['organization_id' => $organizationA->id, 'is_default' => true]);
        $defaultB = TicketPriority::factory()->create(['organization_id' => $organizationB->id, 'is_default' => true]);

        $defaultA->update(['name' => 'Renamed Normal']);

        // TicketPriority.is_default has no boolean cast (pre-existing) -
        // SQLite hands back 0/1.
        $this->assertEquals(1, $defaultA->fresh()->is_default);
        $this->assertEquals(1, $defaultB->fresh()->is_default, "org B's default must survive org A's save");
    }

    public function test_setting_a_new_default_unsets_the_previous_one_within_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $oldDefault = TicketPriority::factory()->create(['organization_id' => $organization->id, 'is_default' => true]);
        $newDefault = TicketPriority::factory()->create(['organization_id' => $organization->id, 'is_default' => false]);

        $newDefault->update(['is_default' => true]);

        $this->assertEquals(0, $oldDefault->fresh()->is_default);
        $this->assertEquals(1, $newDefault->fresh()->is_default);
    }

    public function test_seed_for_creates_the_full_starter_set_scoped_to_the_organization(): void
    {
        $organization = Organization::factory()->create();

        TicketPrioritySeeder::seedFor($organization);

        $this->assertSame(count(TicketPrioritySeeder::defaults()), TicketPriority::where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('ticket_priorities', ['organization_id' => $organization->id, 'name' => 'Normal', 'is_default' => true]);
    }
}

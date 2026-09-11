<?php

namespace Tests\Feature\Organization;

use App\Models\Label;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Fase 3B — fifth and last model isolated. Unlike TicketType/TicketPriority/
 * ProjectStatus, Label has no is_default column (no single-default cleanup
 * to test) and no seedFor() (no starter set - see
 * App\Support\OrganizationDefaults's docblock for why), so this mirrors only
 * the scope/policy/observer sections of
 * Tests\Feature\Organization\TicketTypeTenantIsolationTest.
 */
class LabelTenantIsolationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function memberOf(Organization $organization): User
    {
        $user = $this->userWithPermissions([
            'View label', 'Update label', 'Delete label', 'Create label',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    // ------------------------------------------------------------- scope

    public function test_a_user_does_not_see_another_organizations_label(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = Label::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse(Label::visibleTo($user)->whereKey($foreign->id)->exists());
    }

    public function test_a_legacy_label_with_no_organization_is_visible_to_everyone(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $legacy = Label::factory()->create(['organization_id' => null]);

        $this->assertTrue(Label::visibleTo($user)->whereKey($legacy->id)->exists());
    }

    // -------------------------------------------------------------- policy

    public function test_a_user_cannot_view_another_organizations_label(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = Label::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse($user->can('view', $foreign));
        $this->assertFalse($user->can('update', $foreign));
        $this->assertFalse($user->can('delete', $foreign));
    }

    // ------------------------------------------------------------ observer

    public function test_creating_a_label_stamps_the_users_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $this->actingAs($user);
        $label = Label::create(['name' => 'Urgent', 'color' => '#ff0000']);

        $this->assertSame($organization->id, $label->organization_id);
    }

    /**
     * The inline createOptionForm path on TicketForm's labels Select saves
     * a new, non-persisted model directly (fill() + save()) rather than
     * calling the static create() helper - confirms LabelObserver::creating()
     * still fires for that path too, since both funnel through the same
     * Eloquent creating event.
     */
    public function test_creating_a_label_via_fill_and_save_also_stamps_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $this->actingAs($user);
        $label = new Label;
        $label->fill(['name' => 'Blocked', 'color' => '#000000']);
        $label->save();

        $this->assertSame($organization->id, $label->fresh()->organization_id);
    }

    public function test_creating_a_label_without_an_authenticated_user_leaves_organization_id_null(): void
    {
        $label = Label::factory()->create();

        $this->assertNull($label->organization_id);
    }
}

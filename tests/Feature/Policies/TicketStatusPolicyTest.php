<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Audit finding (2026-09) before Fase 7: TicketStatusPolicy had zero
 * object-level check on view/update/delete - a global permission string
 * was enough to reach ANY TicketStatus by id, including a "custom"
 * (project_id set) status belonging to a project in another Organization
 * entirely. Unlike Activity/TicketType/TicketPriority/ProjectStatus/Label
 * (Fase 3B), TicketStatus was never given its own organization_id column -
 * it already had project_id (global default when null, custom to one
 * project when set), and a project already belongs to exactly one
 * Organization, so the fix delegates to Project::accessibleBy() instead of
 * duplicating a second scope column - the same shape EpicPolicy already
 * uses for the identical "no permissions of its own, ask the parent
 * project" relationship.
 */
class TicketStatusPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function userWithStatusPermissions(): User
    {
        return $this->userWithPermissions([
            'View ticket status', 'Update ticket status', 'Delete ticket status',
        ]);
    }

    public function test_a_global_ticket_status_is_visible_to_anyone_with_the_permission(): void
    {
        $user = $this->userWithStatusPermissions();
        $global = TicketStatus::factory()->create(['project_id' => null]);

        $this->assertTrue($user->can('view', $global));
        $this->assertTrue($user->can('update', $global));
        $this->assertTrue($user->can('delete', $global));
    }

    /**
     * The exact scenario the audit flagged: a project-scoped ("custom")
     * status belonging to a project in an Organization the user has no
     * membership in at all.
     */
    public function test_a_custom_ticket_status_of_an_inaccessible_project_is_denied(): void
    {
        $user = $this->userWithStatusPermissions();

        $otherOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $otherOrganization->id,
            'status_type' => 'custom',
        ]);
        $foreignStatus = TicketStatus::factory()->create(['project_id' => $foreignProject->id]);

        $this->assertFalse($user->can('view', $foreignStatus));
        $this->assertFalse($user->can('update', $foreignStatus));
        $this->assertFalse($user->can('delete', $foreignStatus));
    }

    public function test_a_custom_ticket_status_of_an_accessible_project_is_allowed(): void
    {
        $user = $this->userWithStatusPermissions();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        $ownProject = Project::factory()->create([
            'organization_id' => $organization->id,
            'status_type' => 'custom',
        ]);
        $ownStatus = TicketStatus::factory()->create(['project_id' => $ownProject->id]);

        $this->assertTrue($user->can('view', $ownStatus));
        $this->assertTrue($user->can('update', $ownStatus));
        $this->assertTrue($user->can('delete', $ownStatus));
    }

    /**
     * A user who has never had the flat permission at all must still be
     * denied even for a status they could otherwise reach project-wise -
     * the permission check and the project-accessibility check are both
     * required (AND), neither one alone is enough.
     */
    public function test_a_custom_ticket_status_of_an_accessible_project_still_needs_the_flat_permission(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        $ownProject = Project::factory()->create([
            'organization_id' => $organization->id,
            'status_type' => 'custom',
        ]);
        $ownStatus = TicketStatus::factory()->create(['project_id' => $ownProject->id]);

        $this->assertFalse($user->can('view', $ownStatus));
        $this->assertFalse($user->can('update', $ownStatus));
        $this->assertFalse($user->can('delete', $ownStatus));
    }
}

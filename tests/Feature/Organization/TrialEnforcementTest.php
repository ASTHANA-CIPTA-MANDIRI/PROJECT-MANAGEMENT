<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Fase 6 — AuthServiceProvider::denyIfOrganizationLocked() (a Gate::before
 * callback) locks a user out of every ability on the SaaS-gated models the
 * moment their Organization's trial expires with no subscription, read and
 * write alike (subscription-model-direction.md, 2026-09-09 revision).
 */
class TrialEnforcementTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function memberOf(Organization $organization, string $role = 'owner'): User
    {
        $user = $this->userWithPermissions([
            'View project', 'Update project', 'Delete project', 'Create project', 'View ticket',
        ]);
        $organization->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_a_user_in_a_trial_expired_organization_cannot_view_its_project(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('view', $project));
    }

    public function test_a_user_in_a_trial_expired_organization_cannot_create_a_project(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);

        $this->assertFalse($user->can('create', Project::class));
    }

    public function test_a_user_in_a_trial_expired_organization_cannot_view_a_ticket(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'owner_id' => $user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $user->id]);

        $this->assertFalse($user->can('view', $ticket));
    }

    public function test_a_locked_out_owner_can_still_reach_their_organization(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization, 'owner');

        $this->assertTrue($user->can('view', $organization));
        $this->assertTrue($user->can('update', $organization));
    }

    public function test_a_user_in_an_organization_still_on_trial_is_unaffected(): void
    {
        $organization = Organization::factory()->onTrial()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('view', $project));
        $this->assertTrue($user->can('create', Project::class));
    }

    public function test_a_user_in_a_grandfathered_organization_is_unaffected(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => null]);
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('view', $project));
        $this->assertTrue($user->can('create', Project::class));
    }

    /**
     * A user with no Organization at all (legacy data, or simply never
     * provisioned one) is governed purely by the pre-existing Policy logic
     * — this Gate::before callback has nothing to gate without an
     * Organization in play, unchanged from before Fase 6.
     */
    public function test_a_user_with_no_organization_at_all_is_unaffected_by_this_gate(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('view', $project));
    }

    /**
     * A raw permission-string check (no model argument) must never be
     * caught by this gate, locked-out Organization or not — it has no
     * model class to match against TRIAL_GATED_MODELS.
     */
    public function test_a_plain_permission_string_check_is_never_gated(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);

        $this->assertTrue($user->can('View project'));
    }
}

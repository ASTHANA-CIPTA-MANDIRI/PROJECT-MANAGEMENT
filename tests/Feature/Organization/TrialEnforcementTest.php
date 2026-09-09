<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

    /**
     * Audit finding (2026-09-09): a legacy/pre-tenant project
     * (organization_id === null) must stay reachable even when the acting
     * user's own *active* Organization is separately trial-expired — this
     * project has nothing to do with that Organization. Before this fix,
     * denyIfOrganizationLocked() read only the user's active context and
     * would have wrongly locked this project out too.
     */
    public function test_a_legacy_project_with_no_organization_is_unaffected_by_the_users_own_locked_organization(): void
    {
        $lockedOrganization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($lockedOrganization);
        $legacyProject = Project::factory()->create(['organization_id' => null, 'owner_id' => $user->id]);

        $this->assertTrue($user->can('view', $legacyProject));
        $this->assertTrue($user->can('update', $legacyProject));
    }

    public function test_denial_carries_a_clear_message_about_the_trial(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $response = Gate::forUser($user)->inspect('view', $project);

        // Default app locale is 'id' (see TranslationCompletenessTest's own
        // docblock) - the message renders through lang/id.json's catalog.
        $this->assertFalse($response->allowed());
        $this->assertStringContainsString('uji coba', $response->message());
    }

    // -------------------------------------------------------- scope-level
    // (Gate::before only fires for authorize()/can($ability, $model) calls -
    // these cover the query-scope path (dashboard widgets, exports, search)
    // that never goes through Gate at all, per the 2026-09-09 audit.)

    public function test_project_scope_accessible_by_excludes_a_trial_expired_organizations_projects(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'owner_id' => $user->id]);

        $this->assertFalse(Project::accessibleBy($user)->whereKey($project->id)->exists());
    }

    public function test_project_scope_accessible_by_still_includes_a_legacy_project_regardless_of_the_users_locked_organization(): void
    {
        $lockedOrganization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($lockedOrganization);
        $legacyProject = Project::factory()->create(['organization_id' => null, 'owner_id' => $user->id]);

        $this->assertTrue(Project::accessibleBy($user)->whereKey($legacyProject->id)->exists());
    }

    public function test_ticket_scope_visible_to_excludes_a_ticket_the_user_owns_when_its_organization_is_trial_expired(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $user->id]);

        // owner_id alone would normally be enough to see this ticket - the
        // organization lock must still exclude it.
        $this->assertFalse(Ticket::visibleTo($user)->whereKey($ticket->id)->exists());
    }

    public function test_ticket_scope_visible_to_still_includes_an_owned_ticket_on_a_legacy_project(): void
    {
        $lockedOrganization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($lockedOrganization);
        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $ticket = Ticket::factory()->create(['project_id' => $legacyProject->id, 'owner_id' => $user->id]);

        $this->assertTrue(Ticket::visibleTo($user)->whereKey($ticket->id)->exists());
    }
}

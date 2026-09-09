<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List projects');
    }

    /**
     * Determine whether the user can view the model.
     *
     * Phase 5.4.3: an Organization Owner/Admin can view any project in the
     * Organization they manage without needing the flat `View project`
     * Spatie permission too — that permission still gates the *other* two
     * legitimate reasons isAccessibleBy() can be true (being the project's
     * owner_id, or a project_users member), which are unaffected by this
     * OR-branch and still require it exactly as before.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Project $project)
    {
        return ($user->can('View project') && $project->isAccessibleBy($user))
            || $project->isManageableThroughOrganizationBy($user);
    }

    /**
     * Determine whether the user can create models.
     *
     * Phase 5.3B first added the Organization-authority requirement here,
     * but paired it with the flat `Create project` permission using AND —
     * meaning Organization role never actually replaced needing that
     * permission, it only added a second condition on top of it. Nothing in
     * this app's Organization onboarding (CreateOrganization, the unified
     * "Add member" action, invitation acceptance) ever grants a Spatie
     * role/permission, and the only real-world non-Super-Admin role
     * (EmployeeRoleSeeder) does not include `Create project` — so no real
     * Organization Owner/Admin could ever satisfy the AND, regardless of
     * how correct their Organization authority was (Phase 5.4.3 audit).
     *
     * Phase 5.4.3 fix — `Create project` is dropped from this ability
     * entirely, not turned into an OR alternative: an OR would have let the
     * flat permission alone satisfy create() with no Organization at all
     * (or a Member who happens to separately hold it), which directly
     * contradicts this phase's own required rules ("no organization context
     * → deny, no exceptions", "Member → deny, no exceptions", "Super Admin
     * gets no implicit organization access"). Every one of those scenarios
     * is *only* denied when the permission plays no role at all in the
     * outcome — verified by walking every required scenario in this
     * phase's own test matrix, none of which need the permission to still
     * matter here. The permission definition itself is untouched (still
     * seeded, still assignable to a custom Role) — only this one ability
     * stops consulting it, because Organization authority alone already
     * fully answers the question this ability asks. `viewAny()` is
     * deliberately left alone: it has no Organization angle at all (a
     * platform-wide "can this user see the Projects module" gate), so
     * nothing about it is redundant.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        $organization = OrganizationContext::current($user);

        return $organization !== null && $organization->isManageableBy($user);
    }

    /**
     * Determine whether the user can update the model.
     *
     * Phase 5.4.3: same OR-shape as view() above — Organization authority
     * is a second, sufficient path alongside the existing permission-gated
     * owner_id/project_users path, which is untouched.
     *
     * Fase 6b: narrowed from isManageableThroughOrganizationBy() (Owner+
     * Admin) to isOwnerManageableThroughOrganizationBy() (Owner only) —
     * changing a project's settings is exactly the "Admin/Agent must not"
     * authority subscription-model-direction.md calls out. An Admin who is
     * also a project_users member with the manage role still updates it
     * through the unchanged first branch, same as before.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Project $project)
    {
        return ($user->can('Update project') && $project->isManageableBy($user))
            || $project->isOwnerManageableThroughOrganizationBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Phase 5.4.3: same OR-shape as update() above.
     *
     * Fase 6b: same narrowing as update() above — deleting a project is the
     * clearest "Admin/Agent must not" case of all.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Project $project)
    {
        return ($user->can('Delete project') && $project->isManageableBy($user))
            || $project->isOwnerManageableThroughOrganizationBy($user);
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * Deliberately left unchanged by Phase 5.4.3: unlike create(), there is
     * no Project instance here to verify an Organization match against
     * (making it unsafe to reuse isManageableThroughOrganizationBy(), which
     * needs a concrete project's organization_id — see that method's own
     * docblock for why calling it without one would be a real
     * cross-organization hole), and unlike delete() below, this ability has
     * no per-record fallback of its own to fall back on if broadened
     * carelessly. It only ever gates whether the bulk-delete button/action
     * is offered at all — every selected record is still independently
     * re-checked against the real, already-fixed delete() ability by
     * App\Support\BulkDeleteAuthorizer, which is the actual security
     * boundary here. An Organization Owner/Admin without the flat `Delete
     * project` permission simply will not see the bulk button; the
     * single-record DeleteAction (delete(), fixed above) still works for
     * them. Broadening this coarse gate is left for a follow-up phase, to
     * avoid widening unrelated, already-working behavior (e.g. a user
     * bulk-deleting their own directly-owned, organization-less projects)
     * without dedicated test coverage for that case.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete project');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Project $project)
    {
        return $this->delete($user, $project);
    }

    /**
     * Bulk restoring is the undo of bulk delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restoreAny(User $user)
    {
        return $this->deleteAny($user);
    }
}

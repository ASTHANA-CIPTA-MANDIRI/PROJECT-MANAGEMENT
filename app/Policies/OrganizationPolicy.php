<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Organization RBAC (Phase 4) is intentionally its own authorization axis,
 * completely separate from the Spatie-backed platform permission system
 * every other Policy in this app pairs with an object-level check (see
 * ProjectPolicy) — there is no "View organization"/"Update organization"
 * Spatie permission, and none is added here. Ability is judged purely by
 * the user's role on this specific organization (organization_users.role),
 * exactly mirroring how ProjectPolicy pairs a permission with
 * Project::isAccessibleBy()/isManageableBy(), minus the permission half.
 */
class OrganizationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Phase 5.2 — anyone who can reach the panel at all may create their
     * own organization; there is no Spatie permission for this (same reason
     * none of this Policy's other abilities have one) and no membership to
     * check yet, since the organization doesn't exist until after this
     * passes. Still a real Policy method — not a bypass — so
     * CreateOrganization's create() action has a single, testable gate to
     * call, the same as every other write in this app.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $organization->isAccessibleBy($user);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $organization->isManageableBy($user);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $organization->isManageableBy($user);
    }

    /**
     * Phase 5 — Organization Management. $role is Livewire/Filament state
     * the client controls, never trusted at face value: it must be one of
     * the app's known Organization roles before anything else is even
     * evaluated, exactly the lesson TicketForm's Phase 3B fix already
     * applied to a client-supplied project_id.
     *
     * An Admin may change a Member's or another Admin's role, but never an
     * Owner's — and never promote anyone *to* Owner — only an Owner may
     * touch Owner status at all. The organization's sole Owner can never be
     * demoted by anyone, Owner included, since that would leave the
     * organization without anyone who can manage it.
     */
    public function updateMemberRole(User $user, Organization $organization, User $target, string $role): bool
    {
        if (! array_key_exists($role, config('system.organizations.affectations.roles.list'))) {
            return false;
        }

        if (! $organization->isManageableBy($user)) {
            return false;
        }

        $targetCurrentRole = $organization->roleOf($target);

        if ($targetCurrentRole === null) {
            return false;
        }

        if (($targetCurrentRole === 'owner' || $role === 'owner') && ! $organization->isOwnedBy($user)) {
            return false;
        }

        if ($targetCurrentRole === 'owner' && $role !== 'owner' && $organization->ownerCount() <= 1) {
            return false;
        }

        return true;
    }

    /**
     * Phase 5.3A, tightened by Phase 5.4.1 — adding a brand new membership,
     * whether immediately (an existing User attached directly) or via an
     * invitation accepted later (App\Http\Livewire\AcceptOrganizationInvitation)
     * — the authority question is identical either way, so both paths share
     * this one method rather than duplicating it.
     *
     * $role is client-controlled Livewire/Filament state, checked against a
     * hard-coded allow-list — deliberately NOT
     * config('system.organizations.affectations.roles.list') (which still
     * includes 'owner', a role this ability must never grant at all,
     * regardless of the actor). Owner/admin can add a Member or an Admin;
     * neither can add an Owner through this ability — Owner assignment is a
     * separate, not-yet-built feature (ownership transfer), never a
     * side-effect of onboarding a member. There is no target user's
     * *current* role to check here (they are not a member yet, by
     * definition of this being an add rather than an update — see
     * updateMemberRole() for changing an existing member's role, which is
     * unaffected by this restriction).
     */
    public function addMember(User $user, Organization $organization, string $role): bool
    {
        if (! in_array($role, ['admin', 'member'], true)) {
            return false;
        }

        return $organization->isManageableBy($user);
    }

    /**
     * Phase 5.4 — canceling a PENDING invitation. Any Owner/Admin of the
     * invitation's own Organization may revoke it, regardless of which role
     * it offers: unlike updateMemberRole()/addMember(), revoking can never
     * create a new Owner or touch an existing membership at all — it only
     * ever *removes* a future possibility, so there is no privilege to
     * protect against here the way "only an Owner touches Owner" protects
     * addMember()/updateMemberRole().
     */
    public function revokeInvitation(User $user, Organization $organization): bool
    {
        return $organization->isManageableBy($user);
    }

    /**
     * An Admin may remove a Member or another Admin, but never an Owner.
     * The sole Owner can never be removed by anyone — the organization must
     * always keep at least one Owner able to manage it.
     */
    public function removeMember(User $user, Organization $organization, User $target): bool
    {
        if (! $organization->isManageableBy($user)) {
            return false;
        }

        $targetCurrentRole = $organization->roleOf($target);

        if ($targetCurrentRole === null) {
            return false;
        }

        if ($targetCurrentRole === 'owner' && (! $organization->isOwnedBy($user) || $organization->ownerCount() <= 1)) {
            return false;
        }

        return true;
    }
}

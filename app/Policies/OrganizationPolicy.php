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
     * Phase 5.3A — adding a brand new membership (not updating an existing
     * one). $role is client-controlled Livewire/Filament state, validated
     * against the allow-list first — exactly the same rule
     * updateMemberRole() applies to its own $role, so a request cannot
     * smuggle in "super_admin"/"platform_admin"/anything not in
     * config('system.organizations.affectations.roles.list').
     *
     * An Admin may add a Member or another Admin, but never an Owner — only
     * an Owner may hand out the Owner role at all, mirroring
     * updateMemberRole()'s "only an Owner touches Owner" rule for a
     * brand-new row instead of an existing one. There is no target user's
     * *current* role to check here (they are not a member yet, by
     * definition of this being an add rather than an update).
     */
    public function addMember(User $user, Organization $organization, string $role): bool
    {
        if (! array_key_exists($role, config('system.organizations.affectations.roles.list'))) {
            return false;
        }

        if (! $organization->isManageableBy($user)) {
            return false;
        }

        if ($role === 'owner' && ! $organization->isOwnedBy($user)) {
            return false;
        }

        return true;
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

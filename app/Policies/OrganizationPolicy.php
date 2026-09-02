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
}

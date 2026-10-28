<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Subscription plans (price / duration) are platform data: only a Super Admin
 * may see or change them, regardless of any Spatie permission or Organization
 * role (an Owner of an organization is refused too). A plan is never deleted -
 * invoices keep pointing at its code - so every destructive ability is an
 * explicit, tested `false`, not a missing method (Filament fails closed on a
 * missing one, but stating it keeps the rule visible).
 */
class SubscriptionPlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SubscriptionPlan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SubscriptionPlan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SubscriptionPlan $plan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SubscriptionPlan $plan): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SubscriptionPlan $plan): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}

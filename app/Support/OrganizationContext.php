<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * The single place that answers "which Organization is this user currently
 * acting as" (ADR 0001, "Organization Context"). No middleware, Filament
 * tenancy, or request parameter exists for this yet, and Phase 3A
 * deliberately doesn't add one — this is the minimal, centralized
 * verify-then-use primitive Project::isAccessibleBy() et al. build on.
 *
 * A previously-selected organization id is never trusted on its own: every
 * call re-checks real membership before using it, so a stale session value
 * (the user was removed from that organization since) can't leak access.
 * Falls back to the user's first organization membership when nothing valid
 * is selected — deterministic and safe for the common case (today, exactly
 * one "Default Organization"), not a full switcher UI (out of scope).
 */
class OrganizationContext
{
    private const SESSION_KEY = 'current_organization_id';

    public static function current(User $user): ?Organization
    {
        $selectedId = session(self::SESSION_KEY);

        if ($selectedId !== null) {
            $organization = $user->organizations()->whereKey($selectedId)->first();

            if ($organization !== null) {
                return $organization;
            }
        }

        return $user->organizations()->orderBy('organization_users.id')->first();
    }

    /**
     * Explicitly select the active organization. Verifies membership before
     * persisting — a claimed organization id is never taken on faith, per
     * ADR 0001's Organization Context rule. No production UI calls this yet
     * (no switcher exists in Phase 3A); it exists so tests can exercise a
     * genuine multi-organization context switch.
     */
    public static function switch(User $user, int $organizationId): bool
    {
        if (! $user->organizations()->whereKey($organizationId)->exists()) {
            return false;
        }

        session([self::SESSION_KEY => $organizationId]);

        return true;
    }
}

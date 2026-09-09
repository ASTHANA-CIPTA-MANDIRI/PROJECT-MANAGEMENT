<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Fase 6 — the single place that answers "is this Organization still
 * allowed to use the app at all" (subscription-model-direction.md,
 * 2026-09-09 revision: the trial clock belongs to the Organization, not
 * to each individual member).
 *
 * Mirrors App\Support\OrganizationContext's shape: a small, stateless
 * check, never trusted implicitly — every call site passes a real
 * Organization instance rather than an id, so there is nothing here for a
 * caller to get wrong by fetching the wrong row.
 */
class TrialGate
{
    /**
     * True when the Organization may still be used — grandfathered
     * (trial_ends_at was never set, e.g. the "Default Organization" from
     * organizations:backfill), still inside its 7-day trial window, or
     * genuinely subscribed (Fase 7).
     */
    public static function active(Organization $organization): bool
    {
        return $organization->trial_ends_at === null
            || $organization->trial_ends_at->isFuture()
            || $organization->isSubscribed();
    }
}

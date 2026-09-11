<?php

namespace App\Observers;

use App\Models\Activity;
use App\Support\OrganizationContext;

/**
 * Fase 3B — stamps every newly created Activity with the creator's current
 * Organization, mirroring ProjectObserver::creating() exactly (same reasoning:
 * the single touch point for every creation path — Filament, console,
 * anything future — since they all funnel through this Eloquent event).
 *
 * Only fires for an authenticated creator with no organization_id already
 * set — console/factory/seeder creation (no authenticated user) is left
 * untouched, so a factory-created Activity stays organization_id === null
 * exactly like Project's equivalent, and Database\Seeders\ActivitySeeder's
 * per-Organization seeding (which sets organization_id explicitly) is
 * never overridden by this.
 */
class ActivityObserver
{
    public function creating(Activity $activity): void
    {
        if ($activity->organization_id === null && auth()->check()) {
            $activity->organization_id = OrganizationContext::current(auth()->user())?->id;
        }
    }
}

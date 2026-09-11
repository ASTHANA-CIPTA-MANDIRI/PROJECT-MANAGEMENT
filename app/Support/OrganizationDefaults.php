<?php

namespace App\Support;

use App\Models\Organization;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\TicketPrioritySeeder;
use Database\Seeders\TicketTypeSeeder;

/**
 * Fase 3B — the starter set of reference data (TicketType, TicketPriority,
 * Label, Activity, ProjectStatus) every newly created Organization gets its
 * own copy of, seeded the moment the Organization is created.
 *
 * Called from both places an Organization can be created:
 * App\Listeners\Concerns\ProvisionsPersonalOrganization (self-serve signup,
 * Fase 6) and App\Filament\Pages\CreateOrganization (the manual page,
 * still reachable for admin-created `type='db'` accounts that never go
 * through auto-provisioning). One shared call site so the two paths can
 * never silently drift apart.
 *
 * Activity, TicketType, and TicketPriority are wired so far; Label/
 * ProjectStatus join this list as Fase 3B extends to them.
 */
class OrganizationDefaults
{
    public static function seed(Organization $organization): void
    {
        ActivitySeeder::seedFor($organization);
        TicketTypeSeeder::seedFor($organization);
        TicketPrioritySeeder::seedFor($organization);
    }
}

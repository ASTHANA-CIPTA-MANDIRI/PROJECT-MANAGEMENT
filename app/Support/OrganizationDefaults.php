<?php

namespace App\Support;

use App\Models\Organization;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\ProjectStatusSeeder;
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
 * Activity, TicketType, TicketPriority, and ProjectStatus are wired here.
 * Label — Fase 3B's fifth and last isolated model — deliberately has no
 * entry: unlike the other four, it never had a seeded starter set (no
 * is_default column, nothing required by a NOT NULL foreign key elsewhere)
 * — a Label is purely user-created tagging, so a fresh Organization simply
 * starts with zero and users create their own via the inline
 * createOptionForm on TicketForm. Label's own organization_id isolation is
 * still fully wired (LabelObserver, LabelPolicy, ListLabels scope) — it
 * just has nothing to copy here.
 */
class OrganizationDefaults
{
    public static function seed(Organization $organization): void
    {
        ActivitySeeder::seedFor($organization);
        TicketTypeSeeder::seedFor($organization);
        TicketPrioritySeeder::seedFor($organization);
        ProjectStatusSeeder::seedFor($organization);
    }
}

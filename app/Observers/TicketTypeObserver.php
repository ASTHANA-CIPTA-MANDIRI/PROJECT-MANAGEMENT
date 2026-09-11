<?php

namespace App\Observers;

use App\Models\TicketType;
use App\Support\OrganizationContext;

/**
 * Keeps a single default ticket type: setting one as default unsets it on
 * every other row. Mirrors TicketStatusObserver, but without the ordering
 * concern since ticket types aren't reorderable.
 *
 * Fase 3B: scoped to the saved row's own organization_id (or NULL, matched
 * with whereNull rather than a plain where() — Eloquent's magic 2-argument
 * where($col, null) does happen to translate to whereNull, but being
 * explicit here avoids relying on that). Before this fix, saving a default
 * in one Organization would have silently un-defaulted every other
 * Organization's default type too — a real cross-tenant write, not just a
 * read leak.
 */
class TicketTypeObserver
{
    public function saved(TicketType $type): void
    {
        if (! $type->is_default) {
            return;
        }

        TicketType::where('id', '<>', $type->id)
            ->where('is_default', true)
            ->when(
                $type->organization_id === null,
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $type->organization_id)
            )
            ->update(['is_default' => false]);
    }

    /**
     * Fase 3B — stamps every newly created TicketType with the creator's
     * current Organization, mirroring ActivityObserver::creating() exactly.
     */
    public function creating(TicketType $type): void
    {
        if ($type->organization_id === null && auth()->check()) {
            $type->organization_id = OrganizationContext::current(auth()->user())?->id;
        }
    }
}

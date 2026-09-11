<?php

namespace App\Observers;

use App\Models\TicketPriority;
use App\Support\OrganizationContext;

/**
 * Keeps a single default ticket priority: setting one as default unsets it
 * on every other row. Mirrors TicketStatusObserver, but without the
 * ordering concern since ticket priorities aren't reorderable.
 *
 * Fase 3B: scoped to the saved row's own organization_id — see
 * TicketTypeObserver::saved()'s docblock for the identical reasoning.
 */
class TicketPriorityObserver
{
    public function saved(TicketPriority $priority): void
    {
        if (! $priority->is_default) {
            return;
        }

        TicketPriority::where('id', '<>', $priority->id)
            ->where('is_default', true)
            ->when(
                $priority->organization_id === null,
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $priority->organization_id)
            )
            ->update(['is_default' => false]);
    }

    /**
     * Fase 3B — stamps every newly created TicketPriority with the
     * creator's current Organization, mirroring ActivityObserver::creating().
     */
    public function creating(TicketPriority $priority): void
    {
        if ($priority->organization_id === null && auth()->check()) {
            $priority->organization_id = OrganizationContext::current(auth()->user())?->id;
        }
    }
}

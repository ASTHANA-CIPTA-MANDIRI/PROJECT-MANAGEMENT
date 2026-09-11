<?php

namespace App\Observers;

use App\Models\Label;
use App\Support\OrganizationContext;

/**
 * Fase 3B — stamps every newly created Label with the creator's current
 * Organization, mirroring ActivityObserver::creating() exactly. Label has
 * no is_default column, so unlike TicketType/TicketPriority/ProjectStatus's
 * Observers there is no single-default cleanup logic to scope alongside
 * this - this is the only lifecycle hook Label needs.
 *
 * Covers both LabelResource's own CRUD and the inline createOptionForm on
 * TicketForm's labels Select - both funnel through Eloquent's create(),
 * which fires this same creating() event either way.
 */
class LabelObserver
{
    public function creating(Label $label): void
    {
        if ($label->organization_id === null && auth()->check()) {
            $label->organization_id = OrganizationContext::current(auth()->user())?->id;
        }
    }
}

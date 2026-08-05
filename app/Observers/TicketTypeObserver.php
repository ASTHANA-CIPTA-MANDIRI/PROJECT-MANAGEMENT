<?php

namespace App\Observers;

use App\Models\TicketType;

/**
 * Keeps a single default ticket type: setting one as default unsets it on
 * every other row. Mirrors TicketStatusObserver, but without the ordering
 * concern since ticket types aren't reorderable.
 */
class TicketTypeObserver
{
    public function saved(TicketType $type): void
    {
        if ($type->is_default) {
            TicketType::where('id', '<>', $type->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}

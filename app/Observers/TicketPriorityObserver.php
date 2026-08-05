<?php

namespace App\Observers;

use App\Models\TicketPriority;

/**
 * Keeps a single default ticket priority: setting one as default unsets it
 * on every other row. Mirrors TicketStatusObserver, but without the
 * ordering concern since ticket priorities aren't reorderable.
 */
class TicketPriorityObserver
{
    public function saved(TicketPriority $priority): void
    {
        if ($priority->is_default) {
            TicketPriority::where('id', '<>', $priority->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}

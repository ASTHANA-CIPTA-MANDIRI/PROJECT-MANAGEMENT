<?php

namespace App\Observers;

use App\Models\ProjectStatus;

/**
 * Keeps a single default project status: setting one as default unsets it on
 * every other row. Mirrors TicketStatusObserver, but without the ordering
 * concern since project statuses aren't reorderable.
 */
class ProjectStatusObserver
{
    public function saved(ProjectStatus $status): void
    {
        if ($status->is_default) {
            ProjectStatus::where('id', '<>', $status->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}

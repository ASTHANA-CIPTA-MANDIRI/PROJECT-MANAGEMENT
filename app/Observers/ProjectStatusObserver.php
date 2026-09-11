<?php

namespace App\Observers;

use App\Models\ProjectStatus;
use App\Support\OrganizationContext;

/**
 * Keeps a single default project status: setting one as default unsets it on
 * every other row. Mirrors TicketStatusObserver, but without the ordering
 * concern since project statuses aren't reorderable.
 *
 * Fase 3B: scoped to the saved row's own organization_id — see
 * TicketTypeObserver::saved()'s docblock for the identical reasoning (a
 * real cross-tenant write bug this closes, not just a read leak).
 */
class ProjectStatusObserver
{
    public function saved(ProjectStatus $status): void
    {
        if (! $status->is_default) {
            return;
        }

        ProjectStatus::where('id', '<>', $status->id)
            ->where('is_default', true)
            ->when(
                $status->organization_id === null,
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $status->organization_id)
            )
            ->update(['is_default' => false]);
    }

    /**
     * Fase 3B — stamps every newly created ProjectStatus with the
     * creator's current Organization, mirroring ActivityObserver::creating().
     * Covers both ProjectStatusResource's own CRUD and the inline
     * createOptionUsing() in ProjectForm.
     */
    public function creating(ProjectStatus $status): void
    {
        if ($status->organization_id === null && auth()->check()) {
            $status->organization_id = OrganizationContext::current(auth()->user())?->id;
        }
    }
}

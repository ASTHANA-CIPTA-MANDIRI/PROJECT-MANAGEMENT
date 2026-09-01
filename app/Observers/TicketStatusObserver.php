<?php

namespace App\Observers;

use App\Models\TicketStatus;

/**
 * Keeps ticket statuses consistent: a single default per scope, and unique,
 * gap-free ordering when a status is inserted or reordered.
 */
class TicketStatusObserver
{
    /**
     * A new status always needs both cascades: it may have been created with
     * is_default already set, and it always needs to claim its spot in the
     * order (pushing anything already sitting there down).
     */
    public function created(TicketStatus $status): void
    {
        $this->enforceSingleDefault($status);
        $this->cascadeOrder($status);
    }

    /**
     * Split from created() (rather than one saved() guarded with
     * wasRecentlyCreated) because wasRecentlyCreated never resets to false on
     * a later save() of the same in-memory instance - a guard built on it
     * would still re-run both cascades for a create-then-update in one
     * request. updated() has no such ambiguity: it only ever fires for an
     * actual update, so wasChanged() alone is enough to skip a save that
     * touches neither field (a rename, a color change, ...).
     */
    public function updated(TicketStatus $status): void
    {
        if ($status->wasChanged('is_default')) {
            $this->enforceSingleDefault($status);
        }

        if ($status->wasChanged('order')) {
            $this->cascadeOrder($status);
        }
    }

    private function enforceSingleDefault(TicketStatus $status): void
    {
        if (! $status->is_default) {
            return;
        }

        $query = TicketStatus::where('id', '<>', $status->id)
            ->where('is_default', true);
        if ($status->project_id) {
            $query->where('project_id', $status->project_id);
        }
        $query->update(['is_default' => false]);
    }

    private function cascadeOrder(TicketStatus $status): void
    {
        $query = TicketStatus::where('order', '>=', $status->order)->where('id', '<>', $status->id);
        if ($status->project_id) {
            $query->where('project_id', $status->project_id);
        }
        $toUpdate = $query->orderBy('order', 'asc')
            ->get();
        $order = $status->order;
        // withoutEvents: each save() below would otherwise re-trigger this
        // same observer, opening a new cascade on top of the one already
        // running and growing recursion depth/query count with every status.
        TicketStatus::withoutEvents(function () use ($toUpdate, &$order) {
            foreach ($toUpdate as $i) {
                if ($i->order == $order || $i->order == ($order + 1)) {
                    $i->order = $i->order + 1;
                    $i->save();
                    $order = $i->order;
                }
            }
        });
    }
}

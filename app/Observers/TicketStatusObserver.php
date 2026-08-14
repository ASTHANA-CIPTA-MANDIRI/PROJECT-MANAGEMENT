<?php

namespace App\Observers;

use App\Models\TicketStatus;

/**
 * Keeps ticket statuses consistent: a single default per scope, and unique,
 * gap-free ordering when a status is inserted or reordered.
 */
class TicketStatusObserver
{
    public function saved(TicketStatus $status): void
    {
        if ($status->is_default) {
            $query = TicketStatus::where('id', '<>', $status->id)
                ->where('is_default', true);
            if ($status->project_id) {
                $query->where('project_id', $status->project->id);
            }
            $query->update(['is_default' => false]);
        }

        $query = TicketStatus::where('order', '>=', $status->order)->where('id', '<>', $status->id);
        if ($status->project_id) {
            $query->where('project_id', $status->project->id);
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

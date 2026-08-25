<?php

namespace App\Observers;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Project uses SoftDeletes, but Ticket/Sprint/Epic don't cascade on their
 * own. Without this, a "deleted" project's tickets keep resolving their
 * (trashed) project via Ticket::project()'s withTrashed() and keep showing
 * up in Timesheet, search, and the API even though the project itself is
 * gone from the Project list.
 */
class ProjectObserver
{
    /**
     * Kept low enough that a project with tens of thousands of tickets never
     * holds more than one chunk's worth of models in memory at once.
     */
    private const CHUNK_SIZE = 200;

    /**
     * chunkById (not get()->each) so a large project's tickets/sprints/epics
     * are streamed through in bounded batches instead of being hydrated all
     * at once - and chunkById specifically, because it is safe to delete rows
     * while chunking through them (it re-queries by id > lastId, so it never
     * skips a row shifted by the previous batch's deletes the way an
     * offset-based chunk() would).
     *
     * Wrapped in one transaction so a failure partway through (e.g. a
     * timeout on a huge project) leaves nothing cascaded rather than half of
     * it - callers (API, Filament) also wrap the outer $project->delete()
     * itself in a transaction, and this one nests into that via a savepoint.
     */
    public function deleting(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project->tickets()->chunkById(self::CHUNK_SIZE, fn ($tickets) => $tickets->each->delete());
            $project->sprints()->chunkById(self::CHUNK_SIZE, fn ($sprints) => $sprints->each->delete());
            $project->epics()->chunkById(self::CHUNK_SIZE, fn ($epics) => $epics->each->delete());
        });
    }

    /**
     * A trashed child's deleted_at must fall within this many seconds of the
     * project's own, to count as "taken down by the project's own cascade"
     * rather than independently deleted at some unrelated earlier time. Every
     * row in one cascade run is written within the same request, seconds
     * apart at most - a generous window, since the cost of getting this wrong
     * is only ever restoring too little (never resurrecting an unrelated
     * delete), not data loss.
     */
    private const CASCADE_WINDOW_SECONDS = 30;

    /**
     * The symmetric undo of deleting(): restores the tickets, sprints and
     * epics that are still trashed *and* were deleted around the same time as
     * the project itself - i.e. by the cascade above, not by a user
     * independently deleting one of them before the project was ever
     * touched. deleted_at is still the original trashed timestamp here,
     * since restoring() fires before Eloquent clears it.
     */
    public function restoring(Project $project): void
    {
        if (! $project->deleted_at) {
            return;
        }

        $cutoff = $project->deleted_at->copy()->subSeconds(self::CASCADE_WINDOW_SECONDS);

        DB::transaction(function () use ($project, $cutoff) {
            $project->tickets()->onlyTrashed()->where('deleted_at', '>=', $cutoff)
                ->chunkById(self::CHUNK_SIZE, fn ($tickets) => $tickets->each->restore());
            $project->sprints()->onlyTrashed()->where('deleted_at', '>=', $cutoff)
                ->chunkById(self::CHUNK_SIZE, fn ($sprints) => $sprints->each->restore());
            $project->epics()->onlyTrashed()->where('deleted_at', '>=', $cutoff)
                ->chunkById(self::CHUNK_SIZE, fn ($epics) => $epics->each->restore());
        });
    }
}

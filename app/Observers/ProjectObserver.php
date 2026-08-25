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
}

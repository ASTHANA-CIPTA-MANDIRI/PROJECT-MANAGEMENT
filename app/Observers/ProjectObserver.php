<?php

namespace App\Observers;

use App\Models\Project;

/**
 * Project uses SoftDeletes, but Ticket/Sprint/Epic don't cascade on their
 * own. Without this, a "deleted" project's tickets keep resolving their
 * (trashed) project via Ticket::project()'s withTrashed() and keep showing
 * up in Timesheet, search, and the API even though the project itself is
 * gone from the Project list.
 */
class ProjectObserver
{
    public function deleting(Project $project): void
    {
        $project->tickets()->get()->each->delete();
        $project->sprints()->get()->each->delete();
        $project->epics()->get()->each->delete();
    }
}

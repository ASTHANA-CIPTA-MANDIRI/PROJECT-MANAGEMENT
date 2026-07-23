<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\TicketStatus;

/**
 * Resolves which ticket status counts as "done" for a project.
 *
 * The domain has no explicit "closed" flag, so completion is defined as the
 * status with the highest `order` in the project's workflow (custom statuses
 * for a custom project, the global statuses otherwise). Put the column you
 * treat as Done last in the workflow.
 */
class CompletionResolver
{
    /**
     * The id of the project's completed (final) status, or null if none exist.
     */
    public static function completedStatusId(Project $project): ?int
    {
        return static::statusQuery($project)
            ->orderByDesc('order')
            ->value('id');
    }

    private static function statusQuery(Project $project)
    {
        return $project->status_type === 'custom'
            ? TicketStatus::where('project_id', $project->id)
            : TicketStatus::whereNull('project_id');
    }
}

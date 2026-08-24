<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\TicketStatus;

/**
 * Resolves which ticket statuses count as "done" for a project.
 *
 * Completion is the `is_final` flag on the project's workflow (custom statuses
 * for a custom project, the global statuses otherwise) — the same definition
 * due-date reminders use, so reports and reminders never disagree. A workflow
 * can flag more than one status as final (e.g. Done and Archived).
 *
 * Workflows where nobody has set the flag yet fall back to the single
 * highest-order status, which is how completion used to be guessed.
 */
class CompletionResolver
{
    /**
     * The ids of the project's completed (final) statuses; empty if the
     * workflow has no statuses at all.
     *
     * @return array<int, int>
     */
    public static function completedStatusIds(Project $project): array
    {
        $final = static::statusQuery($project)
            ->where('is_final', true)
            ->pluck('id')
            ->all();

        if ($final !== []) {
            return $final;
        }

        $highestOrder = static::statusQuery($project)
            ->orderByDesc('order')
            ->value('id');

        return $highestOrder ? [(int) $highestOrder] : [];
    }

    private static function statusQuery(Project $project)
    {
        return $project->status_type === 'custom'
            ? TicketStatus::where('project_id', $project->id)
            : TicketStatus::whereNull('project_id');
    }
}

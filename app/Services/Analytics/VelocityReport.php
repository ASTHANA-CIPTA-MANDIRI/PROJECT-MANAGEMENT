<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Team velocity: how much estimated work a project completes per sprint.
 * "Completed" means a ticket reached one of the project's final statuses
 * (see CompletionResolver).
 */
class VelocityReport
{
    /** @var array<int, int> */
    private array $completedStatusIds;

    public function __construct(private Project $project)
    {
        $this->completedStatusIds = CompletionResolver::completedStatusIds($project);
    }

    /**
     * One row per sprint (oldest first) with committed vs completed points.
     *
     * @return Collection<int, array{sprint_id:int, sprint_name:string, committed_points:float, completed_points:float, completed_count:int, is_closed:bool}>
     */
    public function perSprint(): Collection
    {
        $sprints = $this->project->sprints()->orderBy('starts_at')->get();

        // One query for every sprint's tickets instead of one query per sprint.
        $ticketsBySprint = Ticket::query()
            ->whereIn('sprint_id', $sprints->pluck('id'))
            ->get()
            ->groupBy('sprint_id');

        return $sprints->map(function (Sprint $sprint) use ($ticketsBySprint) {
            $tickets = $ticketsBySprint->get($sprint->id, collect());
            $completed = $tickets->whereIn('status_id', $this->completedStatusIds);

            return [
                'sprint_id' => $sprint->id,
                'sprint_name' => $sprint->name,
                'committed_points' => round((float) $tickets->sum('estimation'), 2),
                'completed_points' => round((float) $completed->sum('estimation'), 2),
                'completed_count' => $completed->count(),
                'is_closed' => (bool) $sprint->ended_at,
            ];
        });
    }

    /**
     * Average completed points across the last N *closed* sprints. Returns 0
     * when there is no closed sprint to learn from.
     */
    public function averageVelocity(int $lastN = 3): float
    {
        $closed = $this->perSprint()
            ->filter(fn (array $s) => $s['is_closed'])
            ->values();

        if ($closed->isEmpty()) {
            return 0.0;
        }

        $recent = $closed->slice(-$lastN);

        return round($recent->avg('completed_points'), 2);
    }
}

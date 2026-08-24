<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;

/**
 * Forecasts when a project's remaining work will be finished, by dividing the
 * outstanding estimated work by the team's average velocity and projecting
 * that many sprints forward from today.
 */
class TimelineForecast
{
    public function __construct(private Project $project) {}

    /**
     * @return array{remaining_points:float, avg_velocity:float, sprints_remaining:?int, forecast_date:?string, confident:bool}
     */
    public function forecast(): array
    {
        $remaining = $this->remainingPoints();
        $avgVelocity = (new VelocityReport($this->project))->averageVelocity();

        // Not enough history (no closed sprint with completed work) to forecast.
        if ($avgVelocity <= 0) {
            return [
                'remaining_points' => $remaining,
                'avg_velocity' => $avgVelocity,
                'sprints_remaining' => null,
                'forecast_date' => null,
                'confident' => false,
            ];
        }

        $sprintsRemaining = (int) ceil($remaining / $avgVelocity);
        $sprintLength = $this->averageSprintLengthDays();

        return [
            'remaining_points' => $remaining,
            'avg_velocity' => $avgVelocity,
            'sprints_remaining' => $sprintsRemaining,
            'forecast_date' => now()->addDays((int) round($sprintsRemaining * $sprintLength))->toDateString(),
            'confident' => true,
        ];
    }

    /**
     * Sum of estimations for tickets that have not reached a final status.
     */
    private function remainingPoints(): float
    {
        $completedStatusIds = CompletionResolver::completedStatusIds($this->project);

        return round((float) Ticket::query()
            ->where('project_id', $this->project->id)
            ->when(
                $completedStatusIds !== [],
                fn ($q) => $q->where(fn ($w) => $w->whereNotIn('status_id', $completedStatusIds)
                    ->orWhereNull('status_id'))
            )
            ->sum('estimation'), 2);
    }

    /**
     * Average length (days) of the project's closed sprints; falls back to a
     * two-week sprint when there is no history.
     */
    private function averageSprintLengthDays(): float
    {
        $closed = $this->project->sprints()->whereNotNull('ended_at')->get();

        if ($closed->isEmpty()) {
            return 14.0;
        }

        return (float) $closed->avg(
            fn (Sprint $s) => $s->starts_at->diffInDays($s->ends_at) + 1
        );
    }
}

<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\TicketHour;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resource utilization: how many hours each user logged over a period, and how
 * that compares to their available capacity (business days × hours/day).
 */
class ResourceUtilizationReport
{
    public function __construct(
        private CarbonInterface $start,
        private CarbonInterface $end,
        private ?Project $project = null,
        private float $hoursPerDay = 8.0,
    ) {
    }

    /**
     * @return Collection<int, array{user_id:int, user_name:?string, hours_logged:float, utilization_pct:float}>
     */
    public function perUser(): Collection
    {
        $rows = TicketHour::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->when($this->project, fn ($q) => $q->whereHas(
                'ticket',
                fn ($t) => $t->where('project_id', $this->project->id)
            ))
            ->selectRaw('user_id, SUM(value) as hours')
            ->groupBy('user_id')
            ->get();

        $names = User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');
        $capacity = $this->capacityHours();

        return $rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'user_name' => $names[$row->user_id] ?? null,
            'hours_logged' => round((float) $row->hours, 2),
            'utilization_pct' => $capacity > 0
                ? round((float) $row->hours / $capacity * 100, 1)
                : 0.0,
        ])->sortByDesc('hours_logged')->values();
    }

    /**
     * Available capacity in hours = business days in range × hours per day.
     */
    public function capacityHours(): float
    {
        $days = 0;
        $cursor = $this->start->copy()->startOfDay();
        $end = $this->end->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days * $this->hoursPerDay;
    }
}

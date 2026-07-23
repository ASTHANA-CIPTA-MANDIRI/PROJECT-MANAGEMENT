<?php

namespace App\Services\Analytics;

use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Burn-down for a single sprint: remaining estimated work per day, against the
 * ideal straight-line burn. A ticket's work is "burned" on the day it entered
 * the project's final status.
 */
class BurndownReport
{
    private ?int $completedStatusId;

    public function __construct(private Sprint $sprint)
    {
        $this->completedStatusId = CompletionResolver::completedStatusId($sprint->project);
    }

    /**
     * @return array{total:float, labels:array<int,string>, ideal:array<int,float>, remaining:array<int,float>}
     */
    public function data(): array
    {
        $tickets = Ticket::query()->where('sprint_id', $this->sprint->id)->get();
        $total = round((float) $tickets->sum('estimation'), 2);

        // The day each ticket was completed (null if not completed yet).
        $completedAt = $tickets->mapWithKeys(
            fn (Ticket $t) => [$t->id => $this->completedAt($t)]
        );

        $start = $this->sprint->starts_at->copy()->startOfDay();
        $end = $this->sprint->ends_at->copy()->startOfDay();
        $days = collect(CarbonPeriod::create($start, $end)->toArray());
        $spanDays = max($start->diffInDays($end), 1);

        $labels = [];
        $ideal = [];
        $remaining = [];

        foreach ($days->values() as $index => $day) {
            $labels[] = $day->toDateString();
            $ideal[] = round($total - ($total * $index / $spanDays), 2);

            $burned = $tickets->sum(function (Ticket $t) use ($completedAt, $day) {
                $when = $completedAt[$t->id];

                return $when && $when->lte($day->copy()->endOfDay())
                    ? (float) $t->estimation
                    : 0;
            });

            $remaining[] = round($total - $burned, 2);
        }

        return [
            'total' => $total,
            'labels' => $labels,
            'ideal' => $ideal,
            'remaining' => $remaining,
        ];
    }

    /**
     * When a ticket entered the completed status (latest such transition), or
     * its updated_at if it is currently completed without a recorded activity.
     */
    private function completedAt(Ticket $ticket): ?Carbon
    {
        if (! $this->completedStatusId) {
            return null;
        }

        $activity = TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('new_status_id', $this->completedStatusId)
            ->orderByDesc('created_at')
            ->first();

        if ($activity) {
            return $activity->created_at;
        }

        return $ticket->status_id === $this->completedStatusId
            ? $ticket->updated_at
            : null;
    }
}

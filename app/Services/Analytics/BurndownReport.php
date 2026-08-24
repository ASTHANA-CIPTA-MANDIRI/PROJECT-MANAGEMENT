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
 * one of the project's final statuses.
 */
class BurndownReport
{
    /** @var array<int, int> */
    private array $completedStatusIds;

    public function __construct(private Sprint $sprint)
    {
        $this->completedStatusIds = CompletionResolver::completedStatusIds($sprint->project);
    }

    /**
     * @return array{total:float, labels:array<int,string>, ideal:array<int,float>, remaining:array<int,float>}
     */
    public function data(): array
    {
        $tickets = Ticket::query()->where('sprint_id', $this->sprint->id)->get();
        $total = round((float) $tickets->sum('estimation'), 2);

        // The day each ticket was completed (null if not completed yet). One
        // query for every ticket's activity history instead of one per ticket.
        $completedAtByTicket = $this->completedAtByTicket($tickets);
        $completedAt = $tickets->mapWithKeys(
            fn (Ticket $t) => [$t->id => $completedAtByTicket->get($t->id)
                ?? (in_array((int) $t->status_id, $this->completedStatusIds, true) ? $t->updated_at : null)]
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
     * When each ticket entered a completed status (latest such transition per
     * ticket). Missing keys mean no recorded activity; the caller falls back
     * to the ticket's updated_at when it is currently completed.
     *
     * @param  \Illuminate\Support\Collection<int, Ticket>  $tickets
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    private function completedAtByTicket($tickets)
    {
        if ($this->completedStatusIds === []) {
            return collect();
        }

        return TicketActivity::query()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->whereIn('new_status_id', $this->completedStatusIds)
            // Moving between two final statuses (Done -> Archived) is not a
            // fresh completion, so it must not push the burn day later.
            ->where(fn ($query) => $query->whereNull('old_status_id')
                ->orWhereNotIn('old_status_id', $this->completedStatusIds))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('ticket_id')
            ->map(fn ($activities) => $activities->first()->created_at);
    }
}

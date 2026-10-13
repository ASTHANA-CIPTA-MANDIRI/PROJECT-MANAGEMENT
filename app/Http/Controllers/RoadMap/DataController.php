<?php

namespace App\Http\Controllers\RoadMap;

use App\Http\Controllers\Controller;
use App\Models\Epic;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\Analytics\CompletionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DataController extends Controller
{
    /**
     * Get project epics data
     */
    public function data(Project $project): JsonResponse
    {
        $project = Project::accessibleBy(auth()->user())->where('id', $project->id)->first();
        if (! $project) {
            return response()->json([]);
        }
        // Eager-load what epicObj()/ticketObj() read per row: tickets.hours
        // feeds completudePercentage, tickets.responsible feeds pRes — without
        // this each ticket fires its own hours + responsible query.
        $epics = Epic::where('project_id', $project->id)
            ->with(['tickets.hours', 'tickets.responsible', 'tickets.relations'])
            ->get();

        return response()->json($this->formatResponse($epics, $project));
    }

    /**
     * Format epics to JSON data
     */
    private function formatResponse(Collection $epics, Project $project): Collection
    {
        $tickets = Ticket::where('project_id', $project->id)->whereNull('epic_id')
            ->with(['hours', 'responsible', 'relations'])
            ->orderBy('epic_id')->orderBy('id')->get();

        $this->prepareCompletion($project, $epics->pluck('tickets')->flatten()->merge($tickets));

        $results = collect();
        foreach ($epics->sortBy('id') as $epic) {
            $results->push(collect($this->epicObj($epic)));
            foreach ($epic->tickets as $ticket) {
                $results->push(collect($this->ticketObj($epic, $ticket)));
            }
        }
        foreach ($tickets as $ticket) {
            $results->push(collect($this->ticketObj(null, $ticket)));
        }

        return $results;
    }

    /**
     * Finished tickets count as 100% (an unestimated, finished ticket used to
     * read 0%) and get a real end date for their bar. "Finished" is the same
     * definition the analytics use (CompletionResolver); the finishing date is
     * the last move into a final status, or the last update when the ticket
     * was never moved (imported / seeded straight into Done). One batched
     * query for all tickets.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function prepareCompletion(Project $project, Collection $tickets): void
    {
        $this->doneStatusIds = CompletionResolver::completedStatusIds($project);

        $this->finishedAt = TicketActivity::query()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->whereIn('new_status_id', $this->doneStatusIds)
            ->get(['ticket_id', 'created_at'])
            ->groupBy('ticket_id')
            ->map(fn (Collection $rows) => $rows->max('created_at'))
            ->all();
    }

    /** @var array<int, int> */
    private array $doneStatusIds = [];

    /** @var array<int, \Illuminate\Support\Carbon> */
    private array $finishedAt = [];

    /**
     * Format Epic object
     *
     * @return array
     */
    private function epicObj(Epic $epic)
    {
        return [
            'pID' => $epic->id,
            'pName' => $epic->name,
            'pStart' => $epic->starts_at->format('Y-m-d'),
            'pEnd' => $epic->ends_at->format('Y-m-d').' 23:59:59',
            'pClass' => 'g-custom-task',
            'pLink' => '',
            'pMile' => 0,
            'pRes' => '',
            'pComp' => '',
            'pGroup' => 1,
            'pParent' => 0,
            'pOpen' => 1,
            'pDepend' => $epic->parent_id ?? '',
            'pCaption' => '',
            'pNotes' => '',
            'pBarText' => '',
            'meta' => [
                'id' => $epic->id,
                'epic' => true,
                'parent' => null,
                'slug' => null,
            ],
        ];
    }

    /**
     * Format Ticket object
     *
     * @return array
     */
    private function ticketObj(?Epic $epic, Ticket $ticket)
    {
        $done = in_array($ticket->status_id, $this->doneStatusIds, true);

        // No estimation means no measurable progress, not a finished bar -
        // unless the ticket really is finished.
        $pComp = $done ? 100 : round($ticket->completudePercentage ?? 0, 0);

        // Bar: created -> due date. Without a due date an open ticket runs up
        // to today (flagged unscheduled) and a finished one to the day it
        // was finished.
        $start = $ticket->created_at->copy()->startOfDay();
        $end = $ticket->due_date
            ?? ($done ? ($this->finishedAt[$ticket->id] ?? $ticket->updated_at) : now());
        $end = $end->copy()->startOfDay();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        return [
            'pID' => ($epic?->id ?? 'N').$ticket->id,
            'pName' => $ticket->name,
            'pStart' => '',
            'pEnd' => '',
            'pClass' => 'g-custom-task',
            'pLink' => '',
            'pMile' => 0,
            'pRes' => $ticket->responsible?->name ?? '',
            'pComp' => min($pComp, 100),
            'pGroup' => 0,
            'pParent' => $epic?->id ?? '',
            'pOpen' => 1,
            'pDepend' => '',
            'pCaption' => '',
            'pNotes' => '',
            'pBarText' => '',
            'meta' => [
                'id' => $ticket->id,
                'epic' => false,
                'parent' => $epic?->id ?? null,
                'slug' => $ticket->code,
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'scheduled' => $ticket->due_date !== null,
                'done' => $done,
                'avatar' => $ticket->responsible?->getAttribute('avatar_url'),
                'blocked_by' => $ticket->relations
                    ->where('type', 'blocked_by')
                    ->pluck('relation_id')
                    ->values()
                    ->all(),
            ],
        ];
    }
}

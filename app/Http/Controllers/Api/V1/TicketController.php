<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends ApiController
{
    /**
     * GET /api/v1/projects/{project}/tickets
     *
     * Lists the tickets of a project the user may view. Supports
     * ?filter[status_id|type_id|priority_id|responsible_id], ?sort, ?per_page.
     */
    public function index(Request $request, Project $project)
    {
        // "List tickets" (viewAny), not "View ticket": this mirrors
        // ProjectController::index()/SprintController::index(), both gated by
        // authorize('viewAny', ...) against their own model's "List *"
        // permission. Project access is still enforced separately below.
        $this->authorize('viewAny', Ticket::class);
        $this->assertProjectAccess($project, $request->user());

        $query = Ticket::query()
            ->where('project_id', $project->id)
            ->with(['owner', 'responsible', 'status'])
            ->withCount('comments');

        $this->applyFilters($query, $request, [
            'status_id', 'type_id', 'priority_id', 'responsible_id', 'owner_id', 'sprint_id', 'epic_id',
        ]);
        $this->applySorting($query, $request, ['name', 'code', 'order', 'created_at', 'updated_at'], 'order');

        return TicketResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/projects/{project}/tickets
     *
     * project_id is taken from the route by TicketRequest::prepareForValidation.
     */
    public function store(TicketRequest $request, Project $project)
    {
        $this->assertProjectAccess($project, $request->user());

        // Atomic: the ticket insert plus its lifecycle writes (code/order,
        // epic assignment) commit together or not at all. Queued notifications
        // fire only after commit ($afterCommit on the notification classes).
        $ticket = DB::transaction(fn () => Ticket::create($request->validated()));

        return (new TicketResource($ticket->load(['owner', 'responsible', 'status'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/tickets/{ticket}
     */
    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        return new TicketResource(
            $ticket->load(['owner', 'responsible', 'status'])->loadCount('comments')
        );
    }

    /**
     * PUT|PATCH /api/v1/tickets/{ticket}
     *
     * project_id stays the ticket's own (TicketRequest), so an update can
     * never move a ticket to another project. PUT replaces the ticket, PATCH
     * changes only the fields it carries.
     */
    public function update(TicketRequest $request, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        // Atomic: the update plus the lifecycle writes TicketObserver makes on
        // it (status activity, epic sync) commit together or not at all.
        DB::transaction(fn () => $ticket->update($request->validated()));

        // The observer writes epic_id straight to the table, so re-read the
        // row instead of answering with the value we still hold in memory.
        $ticket->refresh();

        return new TicketResource(
            $ticket->load(['owner', 'responsible', 'status'])->loadCount('comments')
        );
    }

    /**
     * DELETE /api/v1/tickets/{ticket}
     *
     * Soft delete, as in the panel: the ticket keeps its number, which the
     * project's counter never hands out again.
     */
    public function destroy(Ticket $ticket)
    {
        $this->authorize('delete', $ticket);

        $ticket->delete();

        return response()->noContent();
    }
}

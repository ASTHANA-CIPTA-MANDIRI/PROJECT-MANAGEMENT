<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Http\Request;

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
        abort_unless($request->user()->can('View ticket'), 403, 'This action is unauthorized.');
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

        $ticket = Ticket::create($request->validated());

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
}

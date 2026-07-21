<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SprintRequest;
use App\Http\Resources\SprintResource;
use App\Models\Sprint;
use Illuminate\Http\Request;

class SprintController extends ApiController
{
    /**
     * GET /api/v1/sprints
     *
     * Lists sprints of projects the user may access. Supports
     * ?filter[project_id], ?sort, ?per_page, ?page.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Sprint::class);

        $user = $request->user();

        $query = Sprint::query()
            ->whereHas('project', function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
            });

        $this->applyFilters($query, $request, ['project_id', 'epic_id']);
        $this->applySorting($query, $request, ['name', 'starts_at', 'ends_at', 'created_at'], 'starts_at');

        return SprintResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/sprints
     */
    public function store(SprintRequest $request)
    {
        $sprint = Sprint::create($request->validated());

        return (new SprintResource($sprint))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/sprints/{sprint}
     */
    public function show(Sprint $sprint)
    {
        $this->authorize('view', $sprint);

        return new SprintResource($sprint);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SprintRequest;
use App\Http\Resources\SprintResource;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->whereHas('project', fn ($q) => $q->accessibleBy($user));

        $this->applyFilters($query, $request, ['project_id', 'epic_id']);
        $this->applySorting($query, $request, ['name', 'starts_at', 'ends_at', 'created_at'], 'starts_at');

        return SprintResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/sprints
     *
     * The target project comes from the body, so verify the caller actually
     * has access to it — the "Create sprint" permission alone must not let a
     * user add sprints (and their auto-created epics) to arbitrary projects.
     */
    public function store(SprintRequest $request)
    {
        $data = $request->validated();

        $project = Project::findOrFail($data['project_id']);
        $this->assertProjectAccess($project, $request->user());

        // Atomic: the sprint insert plus the epic the SprintObserver creates
        // (and the epic_id write back) commit together or not at all.
        $sprint = DB::transaction(fn () => Sprint::create($data));

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

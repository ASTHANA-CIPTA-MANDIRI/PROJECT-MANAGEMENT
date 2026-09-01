<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends ApiController
{
    /**
     * GET /api/v1/projects
     *
     * Lists projects the authenticated user owns or belongs to. Supports
     * ?filter[type], ?filter[status_id], ?sort, ?per_page, ?page.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        $query = Project::query()
            ->accessibleBy($user)
            ->with(['owner', 'status'])
            ->withCount('tickets');

        $this->applyFilters($query, $request, ['type', 'status_id', 'status_type', 'owner_id']);
        $this->applySorting($query, $request, ['name', 'created_at', 'updated_at'], '-created_at');

        return ProjectResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/projects
     */
    public function store(ProjectRequest $request)
    {
        $project = Project::create($request->validated());

        return (new ProjectResource($project->load(['owner', 'status'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/projects/{project}
     */
    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->load(['owner', 'status', 'users'])->loadCount('tickets'));
    }

    /**
     * PUT|PATCH /api/v1/projects/{project}
     *
     * PUT replaces the project, PATCH changes only the fields it carries.
     * Both are limited to the project's owner and its managing members
     * (ProjectPolicy::update), not to everyone holding "Update project".
     */
    public function update(ProjectRequest $request, Project $project)
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project->load(['owner', 'status'])->loadCount('tickets'));
    }

    /**
     * DELETE /api/v1/projects/{project}
     *
     * Soft delete. ProjectObserver takes the project's tickets, sprints and
     * epics down with it, so the whole cascade runs in one transaction.
     */
    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        DB::transaction(fn () => $project->delete());

        return response()->noContent();
    }
}

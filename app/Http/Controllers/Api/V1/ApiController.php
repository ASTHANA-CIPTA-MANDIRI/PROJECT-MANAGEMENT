<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Base controller for the v1 JSON API. Provides shared query features:
 * whitelisted filtering, whitelisted sorting, and bounded pagination.
 */
abstract class ApiController extends Controller
{
    /** Default number of items per page. */
    protected int $defaultPerPage = 15;

    /** Hard ceiling so a caller cannot request an unbounded page. */
    protected int $maxPerPage = 100;

    /**
     * Apply `?filter[field]=value` constraints, limited to $allowed fields.
     *
     * @param  array<int, string>  $allowed
     */
    protected function applyFilters(Builder $query, Request $request, array $allowed): Builder
    {
        $filters = $request->input('filter', []);

        if (! is_array($filters)) {
            return $query;
        }

        foreach ($filters as $field => $value) {
            if (in_array($field, $allowed, true) && $value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    /**
     * Apply `?sort=field` (asc) or `?sort=-field` (desc), limited to $allowed.
     *
     * @param  array<int, string>  $allowed
     */
    protected function applySorting(Builder $query, Request $request, array $allowed, string $default = 'created_at'): Builder
    {
        $sort = (string) $request->input('sort', $default);
        $direction = 'asc';

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $sort = substr($sort, 1);
        }

        if (! in_array($sort, $allowed, true)) {
            $sort = $default;
            $direction = 'asc';
        }

        return $query->orderBy($sort, $direction);
    }

    /**
     * Resolve the per-page size from the request, clamped to sane bounds.
     */
    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', $this->defaultPerPage);

        return max(1, min($perPage, $this->maxPerPage));
    }

    /**
     * Ensure the user belongs to the project (as owner or member). This gates
     * a project's nested resources without requiring the separate
     * "View project" permission, which is about managing projects themselves.
     */
    protected function assertProjectAccess(Project $project, User $user): void
    {
        $hasAccess = $project->owner_id === $user->id
            || $project->users()->where('users.id', $user->id)->exists();

        abort_unless($hasAccess, 403, 'You do not have access to this project.');
    }
}

<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * Option lists for the "pick a user" selects and filters.
 *
 * Never enumerates the whole users table: a panel user may only see the people
 * they share a project with, and inside a project only that project's
 * contributors. Always plucks at query level, so only two columns are loaded.
 */
class UserOptions
{
    /**
     * Everyone the current user is allowed to see, name keyed by id.
     *
     * @return array<int|string, string>
     */
    public static function visible(): array
    {
        return User::visibleTo(auth()->user())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Users assignable inside one project: its contributors (owner + members).
     * Falls back to visible() when no project is in context yet.
     *
     * $keep holds ids already stored on the record being edited, so an assignee
     * who has since left the project stays selectable instead of silently
     * disappearing from the form.
     *
     * @return array<int|string, string>
     */
    public static function forProject(?Project $project, ...$keep): array
    {
        if (! $project) {
            return static::visible();
        }

        $options = $project->contributors
            ->sortBy('name')
            ->pluck('name', 'id')
            ->toArray();

        $missing = array_diff(array_filter($keep), array_keys($options));
        if ($missing) {
            $options += User::whereKey($missing)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        }

        return $options;
    }

    /**
     * Same as forProject(), for a project id coming from form state. The posted
     * id is client-controlled, so it is resolved through the current user's
     * access scope instead of being trusted as-is.
     *
     * @return array<int|string, string>
     */
    public static function forProjectId($projectId, ...$keep): array
    {
        $project = $projectId
            ? Project::accessibleBy(auth()->user())->whereKey($projectId)->first()
            : null;

        return static::forProject($project, ...$keep);
    }

    /**
     * Users assignable as a Project's owner within one Organization: its
     * members — Phase 5.3B, so a project's owner can never be assigned to
     * someone with no relationship to the Organization it belongs to. Falls
     * back to visible() when there is no Organization to scope by (a legacy
     * null-organization project, or no active Organization context at all —
     * ProjectPolicy::create() already refuses the latter case before a form
     * is ever shown, but this stays a safe default rather than an empty list).
     *
     * $keep mirrors forProject()'s behavior: an owner who has since left the
     * Organization stays selectable on an existing record instead of
     * silently disappearing from the form.
     *
     * @return array<int|string, string>
     */
    public static function forOrganization(?Organization $organization, ...$keep): array
    {
        if (! $organization) {
            return static::visible();
        }

        $options = $organization->users()
            ->orderBy('users.name')
            ->pluck('users.name', 'users.id')
            ->toArray();

        $missing = array_diff(array_filter($keep), array_keys($options));
        if ($missing) {
            $options += User::whereKey($missing)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        }

        return $options;
    }
}

<?php

namespace App\Policies;

use App\Models\Epic;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Epics carry no permissions of their own — they are structure inside a
 * project, so the only question is whether the user can reach that project.
 *
 * The rule is delegated to Project::scopeAccessibleBy() so this policy and the
 * queries the Road Map runs can never drift apart.
 */
class EpicPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Epic $epic): bool
    {
        return $this->belongsToAnAccessibleProject($user, $epic);
    }

    public function update(User $user, Epic $epic): bool
    {
        return $this->belongsToAnAccessibleProject($user, $epic);
    }

    public function delete(User $user, Epic $epic): bool
    {
        return $this->belongsToAnAccessibleProject($user, $epic);
    }

    private function belongsToAnAccessibleProject(User $user, Epic $epic): bool
    {
        if (! $epic->project_id) {
            return false;
        }

        return Project::accessibleBy($user)
            ->whereKey($epic->project_id)
            ->exists();
    }
}

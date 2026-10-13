<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a user may exchange direct messages with: everyone in the Organization
 * they are currently acting as (while its trial is active), plus everyone who
 * owns or belongs to a Project they can access (Project::accessibleBy already
 * drops projects of a locked Organization). Nobody outside those two circles
 * is ever reachable, whatever id a client posts.
 */
class ChatDirectory
{
    /**
     * @return Builder<User>
     */
    public static function contacts(User $user): Builder
    {
        $organization = OrganizationContext::current($user);
        $organizationUsable = $organization !== null && TrialGate::active($organization);

        return User::query()
            ->whereKeyNot($user->id)
            ->where(function (Builder $query) use ($user, $organization, $organizationUsable) {
                $query
                    ->whereIn('id', fn ($sub) => $sub->select('user_id')->from('project_users')
                        ->whereIn('project_id', Project::accessibleBy($user)->select('projects.id')))
                    ->orWhereIn('id', Project::accessibleBy($user)->select('projects.owner_id'));

                if ($organizationUsable) {
                    $query->orWhereIn('id', fn ($sub) => $sub->select('user_id')->from('organization_users')
                        ->where('organization_id', $organization->id));
                }
            });
    }

    /**
     * The user's conversations with people they can still reach. Filtering here
     * (before any limit) keeps stale threads - a contact removed from the
     * project, a locked Organization - out of both the list and the unread badge.
     *
     * @return Builder<Conversation>
     */
    public static function conversations(User $user): Builder
    {
        $reachable = static::contacts($user)->select('users.id');

        return Conversation::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('user_a_id', $user->id)->whereIn('user_b_id', $reachable))
            ->orWhere(fn (Builder $query) => $query->where('user_b_id', $user->id)->whereIn('user_a_id', $reachable)));
    }

    public static function unreadCount(User $user): int
    {
        return static::conversations($user)->unreadFor($user)->count();
    }

    public static function canMessage(User $user, int $otherId): bool
    {
        return $otherId !== $user->id
            && static::contacts($user)->whereKey($otherId)->exists();
    }

    /**
     * True when the user's Organization exists but its trial is over (used to
     * explain an empty Inbox instead of showing it as a bug).
     */
    public static function isLocked(User $user): bool
    {
        $organization = OrganizationContext::current($user);

        return $organization !== null && ! TrialGate::active($organization);
    }
}

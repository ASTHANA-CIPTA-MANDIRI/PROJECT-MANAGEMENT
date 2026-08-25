<?php

namespace App\Support;

/**
 * Per-request cache for User::ticketsCount()/projectsCount(). Those are COUNT
 * queries, and the same user (e.g. a ticket assignee, comment author, or
 * activity author) is often rendered as an avatar many times on one page —
 * without this, each render re-runs both queries even though the answer
 * cannot have changed since the last render on this request.
 *
 * Bound as a singleton (see AppServiceProvider), so it lives for exactly one
 * request/console call/test — never shared across requests.
 */
class UserCountsMemo
{
    /** @var array<int, int> */
    private array $tickets = [];

    /** @var array<int, int> */
    private array $projects = [];

    public function tickets(int $userId, \Closure $resolver): int
    {
        return $this->tickets[$userId] ??= $resolver();
    }

    public function projects(int $userId, \Closure $resolver): int
    {
        return $this->projects[$userId] ??= $resolver();
    }
}

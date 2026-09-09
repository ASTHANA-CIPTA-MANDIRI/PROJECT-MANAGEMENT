<?php

namespace App\Listeners\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6 — every self-serve signup (Breezy self-registration, social
 * login) gets its own Organization automatically, with a 7-day trial and
 * the new user as its sole Owner. Deliberately NOT hooked into
 * App\Observers\UserObserver: that fires for every User creation
 * regardless of path (factories, seeders, admin-created accounts via
 * UserResource), which would provision an Organization for every test
 * user in the suite and for every account an admin creates by hand.
 * Hooking into the same two Registered events App\Listeners\AssignDefaultRole
 * and App\Listeners\SocialRegistration already use keeps this to exactly
 * the two paths that mean "a person just signed themselves up" — the same
 * reasoning that trait already applies to role assignment, extended here.
 */
trait ProvisionsPersonalOrganization
{
    /**
     * Idempotent: a user who somehow already belongs to an Organization
     * (e.g. this listener fires twice, or they were invited into one
     * between account creation and this handler running) is left alone —
     * Option B already limits a user to one Organization at a time, so
     * provisioning a second one here would violate that rule itself.
     *
     * Audit finding (2026-09-09): the exists() check alone is a
     * check-then-act race — two near-simultaneous Registered dispatches
     * for the same brand-new user could both pass it before either
     * commits, since organization_users has no unique index on user_id
     * alone (only on the (organization_id, user_id) pair), so a plain
     * DB-level unique-violation catch cannot close this the way
     * candidateOrganizationName()'s collision retry does for the name.
     * Cache::lock() serializes concurrent callers for this user id
     * instead — it works across PHP-FPM/queue workers regardless of cache
     * driver (including 'file', via real flock()), unlike an in-process
     * mutex which only protects a single request.
     */
    private function provisionPersonalOrganization(User $user): void
    {
        $lock = Cache::lock("provision-organization:{$user->id}", 10);

        if (! $lock->get()) {
            // Another process is already provisioning this exact user —
            // nothing more to do here rather than waiting/retrying.
            return;
        }

        try {
            if ($user->organizations()->exists()) {
                return;
            }

            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    DB::transaction(function () use ($user, $attempt) {
                        $organization = Organization::create([
                            'name' => $this->candidateOrganizationName($user, $attempt),
                            'trial_ends_at' => now()->addDays(7),
                        ]);
                        $organization->users()->attach($user->id, ['role' => 'owner']);
                    });

                    return;
                } catch (QueryException $exception) {
                    // organizations.name is unique — collide, try the next
                    // candidate name rather than leaving the user org-less.
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * "<name>'s Organization", falling back to a numbered/randomized
     * suffix on a collision — mirrors the retry-with-suffix shape
     * App\Jobs\ImportJiraTicketsJob::ticketPrefix() already uses for the
     * same kind of unique-column problem.
     *
     * Not run through __(): same reasoning as
     * BackfillOrganizations::DEFAULT_ORGANIZATION_NAME — this is a
     * generated data value, not UI copy, and the user can rename it
     * anytime via OrganizationSettings. Translating it would make the
     * stored name (and this method's own collision-retry matching)
     * depend on whatever locale happened to be active at signup time.
     */
    private function candidateOrganizationName(User $user, int $attempt): string
    {
        $name = trim($user->name);
        $base = $name !== '' ? "{$name}'s Organization" : "Organization #{$user->id}";

        return $attempt === 0 ? $base : $base.' ('.($attempt + 1).')';
    }
}

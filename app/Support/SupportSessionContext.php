<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationSupportSession;
use App\Models\User;

/**
 * The single place that starts, resolves, and ends a Super Admin's
 * "view as Organization" support session — a temporary, audited, read-only
 * side-channel, deliberately separate from App\Support\OrganizationContext
 * (which answers "which Organization does this user *belong to*"; this
 * class answers "which Organization is this Super Admin *supporting* right
 * now", a question only a Super Admin can ever ask).
 *
 * A session id stored in the session is never trusted on its own: current()
 * always re-verifies it belongs to the calling user, is still active
 * (ended_at is null and expires_at is in the future), and that the caller
 * still is a Super Admin — the same "never trust a cached/session value
 * alone" discipline OrganizationContext::current() applies to organization
 * membership.
 */
final class SupportSessionContext
{
    private const SESSION_KEY = 'support_session_id';

    /**
     * Starts a new support session for $superAdmin against $organization,
     * ending any session already active for them first — only one active
     * session per Super Admin at a time, deliberately, so switching targets
     * always leaves a clean audit trail of exactly when the previous session
     * stopped.
     */
    public static function start(User $superAdmin, Organization $organization, string $reason): OrganizationSupportSession
    {
        abort_unless($superAdmin->isSuperAdmin(), 403);

        self::stop($superAdmin);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $superAdmin->id,
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        session([self::SESSION_KEY => $session->id]);

        return $session;
    }

    /**
     * The Super Admin's current active support session, or null when there
     * is none — including when $user is not a Super Admin, the stored id
     * belongs to someone else, or the session has expired/ended. A stale id
     * found invalid is proactively forgotten so it does not keep being
     * re-checked on every subsequent call.
     */
    public static function current(User $user): ?OrganizationSupportSession
    {
        if (! $user->isSuperAdmin()) {
            return null;
        }

        $id = session(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $session = OrganizationSupportSession::query()
            ->active()
            ->where('super_admin_id', $user->id)
            ->find($id);

        if ($session === null) {
            session()->forget(self::SESSION_KEY);
        }

        return $session;
    }

    /**
     * Ends $user's current active support session (if any) by stamping
     * ended_at, and forgets the session-stored id regardless — so a
     * malformed/foreign id left behind never lingers either.
     *
     * Updates via the query builder rather than the Eloquent model instance:
     * `ended_at` is deliberately not in $fillable (it must never be settable
     * through create()/mass assignment), so a plain `$model->update(...)`
     * call would silently no-op here — the same reasoning
     * PlatformOrganizations::revokeOwnerInvitation() already applies to
     * `organization_invitations.revoked_at`.
     */
    public static function stop(User $user): void
    {
        $session = self::current($user);

        if ($session !== null) {
            OrganizationSupportSession::whereKey($session->id)->update(['ended_at' => now()]);
        }

        session()->forget(self::SESSION_KEY);
    }
}

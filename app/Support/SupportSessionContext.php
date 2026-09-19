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
     *
     * $level defaults to LEVEL_READ_ONLY so every existing caller (and any
     * future one that doesn't care about levels) keeps today's behavior
     * unchanged. A value outside OrganizationSupportSession::LEVELS is
     * never trusted as-is — the caller is Filament form data one step
     * removed from the browser (PlatformOrganizations::startSupportSession),
     * so it gets the same "client-supplied value re-resolved, never trusted
     * past the UI layer" treatment as every other form submit in this app.
     * It silently falls back to the safe (lower-privilege) default rather
     * than throwing: unlike a role grant, where defaulting could hand out
     * more than intended, defaulting *down* to read-only here can never
     * grant a capability the caller didn't clearly ask for.
     */
    public static function start(
        User $superAdmin,
        Organization $organization,
        string $reason,
        string $level = OrganizationSupportSession::LEVEL_READ_ONLY
    ): OrganizationSupportSession {
        abort_unless($superAdmin->isSuperAdmin(), 403);

        if (! in_array($level, OrganizationSupportSession::LEVELS, true)) {
            $level = OrganizationSupportSession::LEVEL_READ_ONLY;
        }

        self::stop($superAdmin);

        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $superAdmin->id,
            'reason' => $reason,
            'level' => $level,
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
     * Phase 3 of Support Action — the single gate every future write action
     * must pass through before touching anything. Deliberately NOT a
     * Laravel Policy and NOT layered on top of App\Support\OrganizationContext:
     * TicketPolicy::update()/SprintPolicy::update()/ProjectPolicy::update()
     * all resolve down to OrganizationContext::current($user) (real
     * membership), and a Super Admin acting through a support session is
     * never a member of the organization they are supporting — reusing
     * those policies would either always fail for a legitimate Support
     * Action, or force an isSuperAdmin() bypass into policies that every
     * other actor in the app is held to, blurring the "three independent
     * axes" boundary this codebase deliberately keeps apart (ADR 0001).
     * This mirrors instead how PlatformOrganizations/OrganizationSupportView
     * already authorize themselves: isSuperAdmin() checked directly, no
     * Policy involved.
     *
     * Reuses current() rather than re-deriving its checks, so "is a Super
     * Admin", "session belongs to this exact caller", "not expired", and
     * "not ended" stay defined in exactly one place:
     *
     *   1. current($user) returns null for anyone who isn't a Super Admin.
     *   2/3. current($user) only ever finds a row with super_admin_id =
     *        $user->id — a session belonging to a different admin (even
     *        with the same session-stored id forced onto this caller) is
     *        invisible here, same as it already is to every other reader.
     *   4/5. current($user)'s active() scope excludes anything expired or
     *        ended.
     *
     * The two checks unique to this method:
     *
     *   6. The session's own level must be SUPPORT_ACTION — a Read Only
     *      session passes every check above and must still be refused here.
     *   7. $targetOrganizationId (the caller's *own* resolved organization
     *      id for whatever it is about to write to — a Ticket's
     *      project->organization_id, a Sprint's the same, a Project's own
     *      organization_id) must equal the session's organization_id.
     *      Re-checked here rather than trusted from the caller, the same
     *      "don't just trust the caller already filtered" discipline
     *      OrganizationSupportView::sprintStatusBreakdown() had to learn the
     *      hard way (security audit finding) — a future action resolving
     *      its target through an organization-scoped query is still
     *      expected to pass that resolved organization id through here,
     *      not skip this call because "it must already be right".
     *
     * Returns the validated session (not void) so a caller that goes on to
     * record an App\Models\OrganizationSupportAction row (Phase 4) has
     * support_session_id/organization_id/actor already at hand without a
     * second lookup.
     */
    public static function authorizeAction(User $user, int $targetOrganizationId): OrganizationSupportSession
    {
        $session = self::current($user);

        abort_unless($session !== null, 403);
        abort_unless($session->isSupportAction(), 403);
        abort_unless($session->organization_id === $targetOrganizationId, 403);

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

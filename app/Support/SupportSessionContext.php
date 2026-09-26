<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationSupportAccessGrant;
use App\Models\OrganizationSupportSession;
use App\Models\User;
use App\Notifications\FullAccessRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
     * Placeholder defaults for the Full Access grant windows (Phase 3).
     * Architecture Design Phase 2 (Bagian 9, Open Question #1) left the
     * exact numbers as an explicit product decision, not an architecture
     * one — kept as named constants here specifically so they are easy to
     * find and change later without touching requestFullAccess()'s or
     * approveFullAccessGrant()'s logic.
     */
    private const FULL_ACCESS_REQUEST_WINDOW_HOURS = 24;

    private const FULL_ACCESS_GRANT_WINDOW_MINUTES = 30;

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

        // LEVEL_FULL_ACCESS is a real, valid value of this column (see its
        // own docblock on OrganizationSupportSession) but must never be
        // reachable through this generic entry point, which has no way to
        // verify an Owner-approved grant exists — treated the same as any
        // other value outside this method's own accepted set, not as a
        // special case, so it falls back to the safe default exactly like
        // an unrecognized string would. Only consumeFullAccessGrant() may
        // ever create a full_access session.
        if ($level === OrganizationSupportSession::LEVEL_FULL_ACCESS
            || ! in_array($level, OrganizationSupportSession::LEVELS, true)) {
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

    // -----------------------------------------------------------------
    // Phase 3 of Support Full Access — the grant lifecycle
    // (Architecture Design Phase 2: REQUESTED -> APPROVED -> CONSUMED,
    // terminal EXPIRED/REVOKED). See OrganizationSupportAccessGrant's own
    // docblock for why this is a separate object from
    // OrganizationSupportSession, and start()'s own docblock above for why
    // LEVEL_FULL_ACCESS can only ever be reached through
    // consumeFullAccessGrant(), never through start() directly.
    // -----------------------------------------------------------------

    /**
     * First step: records that a Super Admin wants Full Access to
     * $organization. Nothing is granted yet — no session exists and none
     * is created here.
     *
     * At most one REQUESTED-or-APPROVED grant may exist for an
     * organization at a time (Architecture Design Phase 2, Decision 17) —
     * enforced with lockForUpdate() inside this transaction so two
     * near-simultaneous requests cannot both pass the "no active grant"
     * check before either commits. On SQLite (this app's test driver)
     * that lock is a no-op — Laravel's SQLite grammar does not emit a
     * locking clause — so this guards true concurrent writers only on the
     * MySQL driver this app runs on outside tests; see Phase 3's own
     * report for why a stronger DB-level constraint was not added here.
     *
     * Phase 4 security audit finding (MEDIUM), verified empirically
     * against real MySQL: when an organization has no grant row at all
     * yet, the lockForUpdate() SELECT below matches zero rows — InnoDB
     * still takes a gap lock on the scanned index range to guard against
     * a phantom insert, and two genuinely concurrent requests both
     * acquire that (mutually compatible) gap lock before either commits.
     * Both then try to INSERT into the same gap, which MySQL resolves by
     * killing one transaction outright with a deadlock error (1213 /
     * SQLSTATE 40001) rather than letting it observe the other's row and
     * fail through the clean abort_if(409) below. The $attempts = 3 on
     * DB::transaction() is Laravel's own bounded deadlock-retry support
     * (Connection::transaction()'s $attempts parameter, detected via
     * causedByDeadlock() — not a custom/unbounded retry loop): on retry,
     * the loser's SELECT now finds the winner's already-committed row,
     * locks that real row instead of an empty gap, and correctly reaches
     * abort_if(409) instead of surfacing a raw QueryException. Does not
     * touch consumeFullAccessGrant() (lockForUpdate() there is a
     * primary-key lookup on an always-existing row, so it never takes a
     * gap lock and never hit this failure mode).
     */
    public static function requestFullAccess(User $superAdmin, Organization $organization, string $reason): OrganizationSupportAccessGrant
    {
        abort_unless($superAdmin->isSuperAdmin(), 403);

        $grant = DB::transaction(function () use ($superAdmin, $organization, $reason) {
            $hasActiveGrant = OrganizationSupportAccessGrant::query()
                ->where('organization_id', $organization->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where(function ($query) {
                    $query->where(function ($query) {
                        $query->whereNull('approved_at')
                            ->where('request_expires_at', '>', now());
                    })->orWhere(function ($query) {
                        $query->whereNotNull('approved_at')
                            ->where('grant_expires_at', '>', now());
                    });
                })
                ->lockForUpdate()
                ->exists();

            abort_if($hasActiveGrant, 409);

            return OrganizationSupportAccessGrant::create([
                'organization_id' => $organization->id,
                'requested_by' => $superAdmin->id,
                'reason' => $reason,
                'requested_at' => now(),
                'request_expires_at' => now()->addHours(self::FULL_ACCESS_REQUEST_WINDOW_HOURS),
            ]);
        }, 3);

        // Notified only once the transaction above has actually committed
        // (we are past DB::transaction() here, not inside it) — the
        // notification's own afterCommit=true additionally protects against
        // any *ambient* outer transaction a caller might be running this
        // inside of, the same belt-and-suspenders discipline
        // FullAccessRequested borrows from TicketCreated. Every Owner is
        // notified (an organization always has at least one — see
        // OrganizationPolicy), never just one arbitrarily picked Owner.
        $owners = $organization->users()->wherePivot('role', 'owner')->get();

        Notification::send($owners, new FullAccessRequested($grant));

        return $grant;
    }

    /**
     * Second step: only the target Organization's Owner may call this,
     * re-verified here against the grant's own organization_id at the
     * moment of approval — never trusted from when the grant was
     * requested — the same "never trust a cached value" discipline
     * OrganizationContext::current() and self::current() already apply
     * elsewhere in this codebase.
     *
     * Explicitly refuses the requester approving their own grant. A Super
     * Admin cannot structurally also be this Organization's Owner in any
     * normal flow, but this is checked anyway as defense in depth, per
     * the architecture design's own instruction not to assume that is
     * impossible.
     *
     * Row-locked and re-checked with isRequested() inside the transaction
     * so a grant that expired, was revoked, or was already approved a
     * moment ago cannot be approved a second time.
     */
    public static function approveFullAccessGrant(User $owner, OrganizationSupportAccessGrant $grant): OrganizationSupportAccessGrant
    {
        return DB::transaction(function () use ($owner, $grant) {
            $grant = OrganizationSupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            abort_unless($grant->organization->isOwnedBy($owner), 403);
            abort_unless($grant->requested_by !== $owner->id, 403);
            abort_unless($grant->isRequested(), 409);

            $grant->update([
                'approved_by' => $owner->id,
                'approved_at' => now(),
                'grant_expires_at' => now()->addMinutes(self::FULL_ACCESS_GRANT_WINDOW_MINUTES),
            ]);

            return $grant->fresh();
        });
    }

    /**
     * Third and final step: turns an APPROVED grant into an active
     * LEVEL_FULL_ACCESS session, atomically. Deliberately does not call
     * self::start() — start()'s own guard explicitly refuses
     * LEVEL_FULL_ACCESS regardless of what is passed to it, so this method
     * is the only path that may ever create a full_access session, and it
     * builds the row directly.
     *
     * Restricted to the grant's own requester: a different Super Admin
     * consuming someone else's approved grant would sever the link
     * between "who the Owner actually approved" and "who is now acting
     * with Full Access" — the same actor-integrity guarantee every other
     * part of Support Center already holds (see
     * App\Filament\Pages\OrganizationSupportView's own docblock on
     * auth()->id() never being anyone but the real actor).
     *
     * Row-locked and re-checked with isApproved() inside the transaction
     * — this is the anti-replay/double-consume guard: a second call
     * against an already-consumed grant fails isApproved() (consumed_at
     * is no longer null) before it can create a second session.
     */
    public static function consumeFullAccessGrant(User $superAdmin, OrganizationSupportAccessGrant $grant): OrganizationSupportSession
    {
        abort_unless($superAdmin->isSuperAdmin(), 403);

        return DB::transaction(function () use ($superAdmin, $grant) {
            $grant = OrganizationSupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            abort_unless($grant->requested_by === $superAdmin->id, 403);
            abort_unless($grant->isApproved(), 409);

            $grant->update(['consumed_at' => now()]);

            // Same one-session-per-admin invariant start() already
            // enforces — consuming a grant ends whatever session (at any
            // level) this Super Admin already had active.
            self::stop($superAdmin);

            $session = OrganizationSupportSession::create([
                'organization_id' => $grant->organization_id,
                'super_admin_id' => $superAdmin->id,
                'access_grant_id' => $grant->id,
                'reason' => $grant->reason,
                'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ]);

            session([self::SESSION_KEY => $session->id]);

            return $session;
        });
    }

    /**
     * Cancels a grant before it is consumed — callable by either the
     * Owner who would approve/has approved it, or the Super Admin who
     * requested it (Architecture Design Phase 2, Open Question #3:
     * self-cancellation by the requester is allowed, since it only
     * withdraws their own request and is not a form of self-approval).
     * Once a grant is CONSUMED it has already done its job; ending the
     * session it produced is self::stop()'s job, not this method's —
     * revoking a consumed grant here would be meaningless, since the
     * session already exists independently of the grant row from that
     * point on.
     *
     * Idempotent: revoking an already-revoked grant is a silent no-op
     * rather than an error, since two near-simultaneous revoke attempts
     * (Owner and Super Admin both cancelling) should not surface a
     * confusing failure.
     */
    public static function revokeFullAccessGrant(User $user, OrganizationSupportAccessGrant $grant): void
    {
        DB::transaction(function () use ($user, $grant) {
            $grant = OrganizationSupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            $isRequester = $user->isSuperAdmin() && $grant->requested_by === $user->id;
            $isOwner = $grant->organization->isOwnedBy($user);

            abort_unless($isRequester || $isOwner, 403);
            abort_if($grant->consumed_at !== null, 409);

            if ($grant->revoked_at !== null) {
                return;
            }

            $grant->update([
                'revoked_at' => now(),
                'revoked_by' => $user->id,
            ]);
        });
    }

    /**
     * The finite set of capabilities a Full Access session may actually be
     * used for. 'change_ticket_status' was the Phase 9 pilot. Phase 11 adds
     * 'delete_ticket' and 'restore_ticket' — the first Destructive Category A
     * capabilities ever opened. Phase 12 adds 'delete_sprint' and
     * 'restore_sprint' — Destructive Category B, the second destructive pair,
     * approved explicitly by human decision for this phase only. Phase 13
     * adds 'delete_project' and 'restore_project' — the highest-blast-radius
     * pair yet, since a Project cascades to every Ticket/Sprint/Epic beneath
     * it (see App\Filament\Pages\OrganizationSupportView::deleteProject()'s
     * own docblock for the full cascade audit). Phase 14 adds
     * 'bulk_delete_ticket', 'bulk_restore_ticket', 'bulk_delete_sprint' and
     * 'bulk_restore_sprint' — the last destructive category: the same
     * Ticket/Sprint delete/restore mutations Phase 11/12 already approved,
     * applied to a whole client-selected batch at once under an explicit
     * all-or-nothing contract (see
     * App\Filament\Pages\OrganizationSupportView::bulkDeleteTicket()'s own
     * docblock). Bulk project delete/restore is deliberately NOT added here
     * — out of Phase 14's scope, pending its own separate design approval.
     * Every other capability (the other Support Action write methods, and
     * every remaining destructive/force-delete/membership/billing
     * capability from the Phase 8 design matrix) is deliberately absent —
     * nothing is added here as a side effect of another phase. A capability
     * is added only once it has been independently approved in its own
     * phase, the same discipline this constant's own history already
     * demonstrates (it started empty in Phase 5).
     *
     * Unlike 'change_ticket_status' (reachable through both a Support Action
     * session and a Full Access session, via authorizeCapability()'s
     * method-existence-gated Support Action branch), 'delete_ticket',
     * 'restore_ticket', 'delete_sprint', 'restore_sprint', 'delete_project',
     * 'restore_project', 'bulk_delete_ticket', 'bulk_restore_ticket',
     * 'bulk_delete_sprint' and 'bulk_restore_sprint' are Full-Access-only by
     * construction: their write methods
     * (App\Filament\Pages\OrganizationSupportView::deleteTicket()/
     * restoreTicket()/deleteSprint()/restoreSprint()/deleteProject()/
     * restoreProject()/bulkDeleteTicket()/bulkRestoreTicket()/
     * bulkDeleteSprint()/bulkRestoreSprint()) deliberately do not call
     * authorizeCapability() at all — they call authorizeFullAccess()
     * directly instead, after checking this allowlist themselves —
     * precisely because authorizeCapability()'s Support Action branch
     * returns before ever consulting this constant (see its own docblock,
     * and
     * test_authorize_capability_allows_a_support_action_session_for_any_capability),
     * which is correct for a capability Support Action already legitimately
     * has (e.g. sprintStart()/sprintStop()/changeSprintDates()/
     * changeProjectStatus(), which remain reachable through a Support Action
     * session via authorizeAction() and are entirely unaffected by this
     * constant), but wrong for one that must never be reachable through a
     * Support Action session in the first place.
     *
     * @var array<int, string>
     */
    private const FULL_ACCESS_CAPABILITIES = [
        'change_ticket_status',
        'delete_ticket',
        'restore_ticket',
        'delete_sprint',
        'restore_sprint',
        'delete_project',
        'restore_project',
        'bulk_delete_ticket',
        'bulk_restore_ticket',
        'bulk_delete_sprint',
        'bulk_restore_sprint',
    ];

    /**
     * Whether $capability is one a Full Access session may actually use.
     * Phase 5 through Phase 8 kept this fail-closed with an empty allowlist
     * (Architecture Design Phase 2's Capability Matrix evaluated several
     * candidate categories, but every one was either already covered by
     * Support Action — non-destructive field edits, never raised to Full
     * Access — or explicitly blocked/deferred pending its own separate
     * design decision: destructive actions, membership, billing,
     * security-sensitive, ownership/identity). Phase 9 is the first
     * capability to actually clear that bar — see FULL_ACCESS_CAPABILITIES
     * above for which one and why only that one.
     *
     * Still a fail-closed allowlist check, not a `return true` stand-in: a
     * Full Access write action must call this (in addition to
     * authorizeFullAccess(), or via authorizeCapability() below which
     * composes both) before acting, the same way every existing Support
     * Action write method (App\Filament\Pages\OrganizationSupportView)
     * validates its target value against an organization-scoped options
     * list in addition to calling authorizeAction() — capability and
     * session/Grant validity are separate, independently-enforced
     * concerns. A future phase extends FULL_ACCESS_CAPABILITIES, not this
     * method's logic, once it has its own capability independently
     * approved.
     */
    public static function isFullAccessCapabilityAllowed(string $capability): bool
    {
        return in_array($capability, self::FULL_ACCESS_CAPABILITIES, true);
    }

    /**
     * Phase 9 — the superset relationship between Full Access and Support
     * Action, made real. A capability method like changeTicketStatus() below
     * wants to accept BOTH a Support Action session (unchanged — gated
     * purely by "the caller reached this specific method at all", the same
     * allowlist-by-method-existence authorizeAction() has always relied on)
     * AND a Full Access session, but only for capabilities explicitly
     * approved in FULL_ACCESS_CAPABILITIES (see that constant's own
     * docblock) — 'change_ticket_status' is the only entry as of Phase 9.
     *
     * The Support Action branch below performs EXACTLY the same checks
     * authorizeAction() already does (session active, right level, right
     * organization) — not a relaxed or reimplemented version of them. The
     * Full Access branch defers entirely to authorizeFullAccess() (Phase 5,
     * unchanged) for the full grant-validity chain — this method adds
     * exactly one new decision on top of it: is $capability in the
     * allowlist. Neither existing gate is weakened; this only composes them.
     */
    public static function authorizeCapability(User $user, int $targetOrganizationId, string $capability): OrganizationSupportSession
    {
        $session = self::current($user);

        if ($session !== null && $session->isSupportAction() && $session->organization_id === $targetOrganizationId) {
            return $session;
        }

        abort_unless(self::isFullAccessCapabilityAllowed($capability), 403);

        return self::authorizeFullAccess($user, $targetOrganizationId);
    }

    /**
     * Full Access's equivalent of authorizeAction() above — but unlike
     * that method, session.level === LEVEL_FULL_ACCESS is never treated as
     * sufficient proof by itself (Phase 5 security audit requirement): a
     * session found at that level must still resolve to a real, valid,
     * CONSUMED Grant whose own organization_id agrees with both the
     * session's and the caller's target — every check below is
     * independent and fails closed, none of them falls back to trusting
     * the session alone if the Grant is missing, broken, or inconsistent.
     *
     * Reuses current() rather than re-deriving its checks, so "is a Super
     * Admin" (read from the authenticated $user passed in — never a
     * request/form/session-payload value), "session belongs to this exact
     * caller", "not expired", "not ended" stay defined in exactly one
     * place — identical to authorizeAction()'s own reasoning.
     *
     * $targetOrganizationId is checked against both the session's own
     * organization_id AND the Grant's own organization_id independently,
     * not assumed to imply each other from a single comparison (Phase 5
     * security audit, organization-isolation requirement) — a forged or
     * stale access_grant_id could otherwise point at a Grant whose
     * organization disagrees with the session it is attached to, and a
     * single ID check would miss exactly that case.
     *
     * Grant expiry is deliberately not re-checked here as a raw
     * timestamp comparison: request_expires_at/grant_expires_at bound the
     * window to request/consume the Grant, both already enforced at the
     * time consumeFullAccessGrant() set consumed_at — re-comparing them
     * against "now" here would incorrectly deny an already-legitimately-
     * consumed session once that historical window has since passed.
     * isConsumed() is the definitive proof those checks already passed;
     * the session's own expires_at (enforced by current() above) is what
     * bounds how long a Full Access session may keep being used.
     *
     * No capability parameter here — same shape as authorizeAction(),
     * which also authorizes only session/level/organization and leaves
     * "which specific action" to the caller. A future Full Access write
     * action calls both this method and isFullAccessCapabilityAllowed()
     * before acting, exactly as Support Action's write methods already
     * combine authorizeAction() with their own target-value validation.
     */
    public static function authorizeFullAccess(User $user, int $targetOrganizationId): OrganizationSupportSession
    {
        $session = self::current($user);

        abort_unless($session !== null, 403);
        abort_unless($session->isFullAccess(), 403);
        abort_unless($session->organization_id === $targetOrganizationId, 403);

        // Level alone is never sufficient — resolve the Grant this
        // session claims to have been consumed from, and fail closed if
        // it is missing, belongs to a different organization than either
        // the session or the caller's target, was never actually
        // consumed, or was revoked through any path.
        abort_unless($session->access_grant_id !== null, 403);

        $grant = $session->accessGrant;

        abort_unless($grant !== null, 403);
        abort_unless($grant->organization_id === $session->organization_id, 403);
        abort_unless($grant->organization_id === $targetOrganizationId, 403);
        abort_unless($grant->isConsumed(), 403);
        abort_unless($grant->revoked_at === null, 403);

        return $session;
    }
}

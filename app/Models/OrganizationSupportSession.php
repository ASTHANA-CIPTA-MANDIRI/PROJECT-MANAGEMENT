<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-boxed, audited record of a Super Admin viewing one Organization's
 * data for support purposes — never a membership, never routed through
 * App\Support\OrganizationContext or any Policy. Created and read only by
 * App\Support\SupportSessionContext, which is the sole place that enforces
 * "one active session per Super Admin" and re-verifies ownership before
 * trusting a session-stored id.
 *
 * State is derived, not a stored enum column — active = ended_at is null and
 * expires_at is still in the future — the same pattern
 * OrganizationInvitation::isPending() already uses instead of a status flag.
 *
 * `level` (Phase 1 of Support Action) is a plain string column with a fixed,
 * application-validated set of values — the same shape as
 * organization_users.role/project_users.role, not a DB enum. It is chosen
 * once when the session starts (SupportSessionContext::start()) and lives
 * for the session's whole lifecycle: it is never changed mid-session, so it
 * expires/ends along with everything else on this row. This phase only
 * makes the level readable — no write capability is gated on it yet.
 */
class OrganizationSupportSession extends Model
{
    /**
     * Read-only by construction: no write action anywhere in the Support
     * Center reads this level yet.
     */
    public const LEVEL_READ_ONLY = 'read_only';

    /**
     * Reserved for Phase 3 (authorizeAction()) — carries no extra
     * capability on its own yet, exactly like a Role a Super Admin holds no
     * permission-checked ability against.
     */
    public const LEVEL_SUPPORT_ACTION = 'support_action';

    /**
     * Phase 3 of Support Full Access. Unlike the two levels above, this one
     * is never reachable through SupportSessionContext::start() directly —
     * that method explicitly refuses this exact value regardless of what
     * is passed to it, because it has no way to verify an Owner-approved
     * grant exists. The only path that ever sets a session to this level
     * is SupportSessionContext::consumeFullAccessGrant(), which builds the
     * session row itself rather than going through start().
     */
    public const LEVEL_FULL_ACCESS = 'full_access';

    /**
     * The only values `level` may ever hold. Being a member of this array
     * does not by itself mean SupportSessionContext::start() will accept
     * the value — see LEVEL_FULL_ACCESS's own docblock for the one
     * exception, which start() enforces explicitly, not by omitting it
     * from this list (it is a real, valid value of this column, just not
     * one that generic entry point may ever set).
     */
    public const LEVELS = [
        self::LEVEL_READ_ONLY,
        self::LEVEL_SUPPORT_ACTION,
        self::LEVEL_FULL_ACCESS,
    ];

    protected $fillable = [
        'organization_id',
        'super_admin_id',
        'access_grant_id',
        'reason',
        'level',
        'started_at',
        'expires_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_id');
    }

    /**
     * The Full Access grant this session was consumed from — null for
     * every read_only/support_action session, since only
     * SupportSessionContext::consumeFullAccessGrant() ever sets
     * access_grant_id.
     */
    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(OrganizationSupportAccessGrant::class, 'access_grant_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /**
     * Whether this session was started at the Support Action level — a
     * pure read of the stored value, not an authorization decision. No
     * write path in the app checks this yet (that is Phase 3's
     * authorizeAction()); today it exists purely so the level can be
     * displayed and tested.
     */
    public function isSupportAction(): bool
    {
        return $this->level === self::LEVEL_SUPPORT_ACTION;
    }

    /**
     * Whether this session was created at the Full Access level — a pure
     * read of the stored value, not an authorization decision. The actual
     * gate for Full Access write actions is
     * SupportSessionContext::authorizeFullAccess(), which uses this.
     */
    public function isFullAccess(): bool
    {
        return $this->level === self::LEVEL_FULL_ACCESS;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }
}

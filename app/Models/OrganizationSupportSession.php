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
     * The only two values `level` may ever hold — read by
     * SupportSessionContext::start() to fall back safely on anything else
     * (see that method's own docblock for why the safe fallback is this
     * list's first entry, LEVEL_READ_ONLY, not an exception).
     */
    public const LEVELS = [
        self::LEVEL_READ_ONLY,
        self::LEVEL_SUPPORT_ACTION,
    ];

    protected $fillable = [
        'organization_id',
        'super_admin_id',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }
}

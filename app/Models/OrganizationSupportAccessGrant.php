<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Owner-approved permission for a Super Admin to start a Full Access
 * support session against one Organization — the object
 * App\Support\SupportSessionContext::requestFullAccess()/
 * approveFullAccessGrant()/consumeFullAccessGrant()/revokeFullAccessGrant()
 * create and transition. See that class's own docblocks for the full
 * lifecycle and why this is a separate table from
 * OrganizationSupportSession (a grant has states — REQUESTED, APPROVED —
 * that exist before any session does).
 *
 * State is derived from timestamp columns, never a stored enum — the same
 * pattern OrganizationSupportSession::isActive() already uses.
 */
class OrganizationSupportAccessGrant extends Model
{
    protected $fillable = [
        'organization_id',
        'requested_by',
        'approved_by',
        'revoked_by',
        'reason',
        'requested_at',
        'request_expires_at',
        'approved_at',
        'grant_expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'request_expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'grant_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * The Full Access session this grant was consumed into, if any — null
     * for every grant still in REQUESTED/APPROVED, or that expired/was
     * revoked before ever being consumed.
     */
    public function session(): HasOne
    {
        return $this->hasOne(OrganizationSupportSession::class, 'access_grant_id');
    }

    /**
     * Awaiting Owner response — not yet approved, revoked, consumed, or
     * past its request window.
     */
    public function isRequested(): bool
    {
        return $this->approved_at === null
            && $this->consumed_at === null
            && $this->revoked_at === null
            && $this->request_expires_at->isFuture();
    }

    /**
     * Owner-approved and not yet consumed into a session, revoked, or past
     * its own (separate, shorter) consume window.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null
            && $this->consumed_at === null
            && $this->revoked_at === null
            && $this->grant_expires_at !== null
            && $this->grant_expires_at->isFuture();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Phase 7 (Full Access UI/UX Gate). A single word key summarizing this
     * grant's current position in its own REQUESTED -> APPROVED -> CONSUMED
     * lifecycle (with REVOKED/EXPIRED as the two terminal off-ramps) —
     * built once here instead of letting the Owner-side
     * (App\Filament\Pages\OrganizationSettings) and Super-Admin-side
     * (App\Filament\Pages\PlatformOrganizations) UI surfaces each
     * re-derive the same branching from the four is*() methods above,
     * which would risk the two surfaces silently disagreeing about what
     * "expired" means. Deliberately a raw string key, not a translated
     * label — translation is a UI-layer concern (see this class's own
     * docblock: "state is derived from timestamp columns, never a stored
     * enum"), so callers map this onto __() themselves.
     *
     * "expired" is inferred, not read from any column: a request whose
     * request_expires_at has passed without ever being approved, or an
     * approval whose grant_expires_at passed without ever being consumed,
     * are both real end states isRequested()/isApproved() above already
     * exclude (both check their own window is still in the future) — this
     * method is what actually surfaces that as its own label instead of
     * leaving a caller to guess why isRequested()/isApproved() both came
     * back false for a grant that was neither revoked nor consumed.
     */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->isConsumed()) {
            return 'consumed';
        }

        if ($this->isApproved()) {
            return 'approved';
        }

        if ($this->approved_at !== null) {
            return 'expired';
        }

        if ($this->isRequested()) {
            return 'requested';
        }

        return 'expired';
    }
}

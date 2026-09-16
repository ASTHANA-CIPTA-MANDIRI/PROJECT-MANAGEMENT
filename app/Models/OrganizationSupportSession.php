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
 */
class OrganizationSupportSession extends Model
{
    protected $fillable = [
        'organization_id',
        'super_admin_id',
        'reason',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 7 — the internal, read-only-from-the-app's-perspective record of one
 * paid subscription period for one Organization. Organization::isSubscribed()
 * is the only thing that reads this in Fase 7; nothing in this phase writes
 * a row here (Fase 8-9, driven by a verified payment event, own creating and
 * updating these).
 *
 * Deliberately not a payment/billing/invoice record: no gateway reference,
 * amount, or currency lives here. See this table's own migration docblock
 * for the full reasoning, and docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * State is derived from timestamp columns, never a stored enum — the same
 * pattern OrganizationInvitation/OrganizationSupportSession/
 * OrganizationSupportAccessGrant already use instead of a status flag:
 * a subscription is active when it has not been cancelled and its period
 * has not ended. isActive() and scopeActive() below must be kept in sync
 * (mirrors OrganizationSupportSession::isActive()/scopeActive()) — one is
 * the single-row PHP check, the other is the same condition pushed into the
 * query for Organization::isSubscribed() so that call never loads rows it
 * only needs to know the existence of.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'starts_at',
        'ends_at',
        'cancelled_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Not cancelled, and either has no end date (unlimited) or its end date
     * is still in the future. Matches trial_ends_at's own "isFuture()"
     * boundary semantics (App\Support\TrialGate) — the instant ends_at is
     * reached, the subscription is expired, not active for one more tick.
     */
    public function isActive(): bool
    {
        return $this->cancelled_at === null
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * The query-level twin of isActive() — used by
     * Organization::isSubscribed() so that check is a single `exists()`
     * query (no rows loaded, no N+1) instead of pulling every subscription
     * row for the organization into PHP to test isActive() on each one.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->where(function (Builder $query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}

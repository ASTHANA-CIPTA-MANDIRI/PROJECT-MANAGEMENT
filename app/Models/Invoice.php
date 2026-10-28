<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 8-9 - one DOKU Checkout payment attempt for one Organization's
 * subscription plan. See this table's own migration docblock for why
 * `status` is a real column here (unlike Subscription's derived-from-
 * timestamps state) and why subscription_id starts null and is only ever
 * backfilled once a payment is verified.
 *
 * Never mutated directly from a controller: created by
 * App\Services\SubscriptionCheckoutService (checkout) and transitioned by
 * App\Services\SubscriptionActivationService (the verified-webhook path)
 * only. Exempt from Filament's Policy registration
 * (tests/Feature/Policies/PolicyRegistrationTest.php) for the same shape
 * of reason Subscription is: never exposed through a Filament Resource,
 * and nothing reads/writes it from user-suppliable input.
 */
class Invoice extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * The only values `status` may ever hold - the same "fixed,
     * application-validated string column" shape as organization_users.role
     * / organization_support_sessions.level.
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'invoice_number',
        'plan_code',
        'duration_days',
        'plan_name',
        'amount',
        'currency',
        'status',
        'doku_request_id',
        'doku_reference',
        'doku_token_id',
        'doku_payment_url',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'duration_days' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * PENDING is the only non-final state - once an invoice reaches paid,
     * failed or expired it must never transition again.
     * App\Services\SubscriptionActivationService checks this before
     * touching a row precisely so a duplicate/replayed DOKU notification
     * (spec section B: notifications "might occasionally" arrive more than
     * once) can never re-run subscription activation or flip an already
     * final invoice to a different final state.
     */
    public function isFinal(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * Whether an `expired` invoice is past the window in which an admin may
     * still recover a late payment (config subscriptions.late_recovery_max_days).
     * Measured from expires_at, the moment the payment page stopped being
     * valid (updated_at only if that is missing); with neither, it is refused.
     * The one definition shared by App\Services\InvoiceSyncer (cheap pre-check,
     * no DOKU call) and SubscriptionActivationService (authoritative, under lock).
     */
    public function isTooOldToRecover(): bool
    {
        $since = $this->expires_at ?? $this->updated_at;
        $maxDays = max(1, (int) config('subscriptions.late_recovery_max_days', 30));

        return $since === null || $since->lt(now()->subDays($maxDays));
    }

    /**
     * Whether this pending invoice's DOKU Checkout payment page has passed
     * its local due-date clock (expires_at) - the app-side "pending ->
     * expired by timeout" transition the DOKU spec recommends instead of
     * trusting an undocumented gateway status value. See
     * App\Console\Commands\ExpirePendingInvoices.
     */
    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Pending invoices whose local due-date clock (expires_at) has already
     * passed: the expire/polling commands have not closed them (yet), so a
     * human may want to look. Same condition as {@see isPastDue()}, as a
     * query; shared by the Super Admin payments summary cards and its
     * "needs attention" filter.
     */
    public function scopePendingPastDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /**
     * Paid but never linked to a Subscription. SubscriptionActivationService
     * sets paid_at and subscription_id in one transaction, so this should
     * never happen: a row matching it is money received without access
     * granted.
     */
    public function scopePaidWithoutSubscription(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID)->whereNull('subscription_id');
    }

    /**
     * Anything a Super Admin should look at: {@see scopePendingPastDue()} or
     * {@see scopePaidWithoutSubscription()}. Single definition for the
     * "needs attention" filter and the per-row indicator
     * ({@see attentionReason()}); keep the two in sync.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->scopes(['pendingPastDue'])->orWhere(fn (Builder $q) => $q->scopes(['paidWithoutSubscription']));
        });
    }

    /**
     * Per-row twin of {@see scopeNeedsAttention()}: 'past_due',
     * 'paid_without_subscription' or null.
     */
    public function attentionReason(): ?string
    {
        if ($this->isPastDue()) {
            return 'past_due';
        }

        if ($this->status === self::STATUS_PAID && $this->subscription_id === null) {
            return 'paid_without_subscription';
        }

        return null;
    }
}

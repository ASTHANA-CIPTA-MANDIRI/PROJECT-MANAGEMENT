<?php

namespace App\Models;

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
}

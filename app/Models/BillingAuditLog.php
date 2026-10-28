<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual billing action by a Super Admin. See the
 * create_billing_audit_logs_table migration's docblock for what is (and,
 * deliberately, is not) stored and why every foreign key is nullOnDelete.
 *
 * Immutable by convention: UPDATED_AT is disabled and nothing edits a row.
 * Rows are only ever created through {@see record()} - the single writer -
 * and removed by the scheduled `model:prune` (Kernel) per {@see prunable()}.
 * Exempt from Filament's Policy registration (PolicyRegistrationTest) for
 * the same reason Invoice is: no Filament Resource exposes it.
 */
class BillingAuditLog extends Model
{
    use HasFactory, MassPrunable;

    const UPDATED_AT = null;

    public const ACTION_CHECK_STATUS = 'invoice.check_status';

    public const ACTION_SYNC = 'invoice.sync';

    // An expired invoice reopened as paid after DOKU confirmed a late payment
    // (InvoiceSyncer::recoverLate). Money + access, so kept as long as a sync.
    public const ACTION_LATE_RECOVERY = 'invoice.late_recovery';

    // Changes to a subscription plan (App\Services\PlanManager). They carry a
    // `subject` ("plan:<code>") and old_value/new_value instead of an invoice.
    public const ACTION_PLAN_CREATE = 'plan.create';

    public const ACTION_PLAN_UPDATE = 'plan.update';

    public const ACTION_PLAN_TOGGLE = 'plan.toggle';

    // A Super Admin set or changed organizations.trial_ends_at, the column that
    // decides whether an organization is locked out (App\Services\OrganizationTrialManager).
    // Carries subject "organization:<id>" and organization_id, no invoice.
    public const ACTION_ORGANIZATION_TRIAL_UPDATE = 'organization.trial_update';

    public const PLAN_ACTIONS = [
        self::ACTION_PLAN_CREATE,
        self::ACTION_PLAN_UPDATE,
        self::ACTION_PLAN_TOGGLE,
    ];

    /**
     * Retention per action: a sync changes money/access so its trail is
     * kept a year (a late recovery likewise), and so is a trial change (it decides whether an organization
     * can use the product at all); a status check and a plan change are kept 90
     * days. An action
     * not listed here (a future one) is never pruned until it is added.
     */
    public const RETENTION_DAYS = [
        self::ACTION_SYNC => 365,
        self::ACTION_LATE_RECOVERY => 365,
        self::ACTION_ORGANIZATION_TRIAL_UPDATE => 365,
        self::ACTION_PLAN_CREATE => 90,
        self::ACTION_PLAN_UPDATE => 90,
        self::ACTION_PLAN_TOGGLE => 90,
        self::ACTION_CHECK_STATUS => 90,
    ];

    protected $fillable = [
        'actor_user_id',
        'invoice_id',
        'organization_id',
        'invoice_number',
        'subject',
        'action',
        'result',
        'reason',
        'old_status',
        'new_status',
        'old_value',
        'new_value',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The single place an audit row is written. Takes only the facts the
     * table is allowed to hold - callers cannot pass a payload, a message
     * or an IP, because there is no parameter for them.
     *
     * A plan change passes $subject ("plan:<code>") and the plan's before/after
     * values as $oldValue / $newValue (compact JSON built by PlanManager)
     * instead of an invoice; they are short, non-sensitive and are the only
     * free-form values the table holds, so callers must never put a secret,
     * payload or message in them.
     *
     * A row about an organization but no invoice (a trial change) passes
     * $organizationId; with an $invoice it is read from the invoice instead.
     *
     * $invoiceNumber is only needed when there is no $invoice model (the
     * row it pointed at no longer exists); otherwise it is read from it.
     */
    public static function record(
        string $action,
        string $result,
        ?Invoice $invoice,
        ?User $actor,
        ?string $reason = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $invoiceNumber = null,
        ?string $subject = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?int $organizationId = null,
    ): self {
        return static::create([
            'actor_user_id' => $actor?->getKey(),
            'invoice_id' => $invoice?->getKey(),
            'organization_id' => $invoice?->organization_id ?? $organizationId,
            'invoice_number' => $invoice?->invoice_number ?? $invoiceNumber,
            'subject' => $subject,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
    }

    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query) {
            foreach (self::RETENTION_DAYS as $action => $days) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('action', $action)
                    ->where('created_at', '<=', now()->subDays($days)));
            }
        });
    }
}

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

    /**
     * Retention per action: a sync changes money/access so its trail is
     * kept a year; a status check changes nothing, so 90 days. An action
     * not listed here (a future one) is never pruned until it is added.
     */
    public const RETENTION_DAYS = [
        self::ACTION_SYNC => 365,
        self::ACTION_CHECK_STATUS => 90,
    ];

    protected $fillable = [
        'actor_user_id',
        'invoice_id',
        'organization_id',
        'invoice_number',
        'action',
        'result',
        'reason',
        'old_status',
        'new_status',
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
    ): self {
        return static::create([
            'actor_user_id' => $actor?->getKey(),
            'invoice_id' => $invoice?->getKey(),
            'organization_id' => $invoice?->organization_id,
            'invoice_number' => $invoice?->invoice_number ?? $invoiceNumber,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
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

<?php

namespace App\Services;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Doku\DokuClient;
use App\Services\Doku\DokuPaymentException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Asks DOKU Check Status about ONE invoice, for a human to inspect
 * ({@see check()}) or to apply ({@see sync()}).
 *
 * There is exactly one way to change an invoice in this codebase -
 * SubscriptionActivationService::process() - and this class is just
 * another caller of it, like the webhook and the polling command. It
 * therefore never writes an Invoice or a Subscription itself; a test greps
 * this file's source to keep it that way.
 *
 * Auditing: both entry points write a BillingAuditLog row if (and only if)
 * an admin $actor is passed. Without an actor (the polling command, the
 * scheduler) nothing is written - that is the system's routine work, which
 * is already visible in its own logs, and an audit table of "who" has
 * nobody to name. The audit write is fail-closed: if it throws, the error
 * propagates, and for a successful sync it sits in the same transaction as
 * the mutation so an unaudited admin change cannot be committed.
 */
class InvoiceSyncer
{
    /**
     * DOKU asks callers not to check status within 60 seconds of a payment.
     * Shared with the polling command's own (private) constant; a Super
     * Admin action never bypasses it, unlike `--invoice=`.
     */
    public const MIN_AGE_SECONDS = 60;

    /**
     * Only terminal DOKU statuses are applied. FAILED is deliberately NOT
     * here: for Checkout the customer may retry with another method, and
     * process() treats it as ignorable anyway.
     */
    public const FORWARDED_STATUSES = ['SUCCESS', 'EXPIRED'];

    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 500;

    /**
     * Throttle budget for the admin actions. Not enforced here (the syncer
     * knows nothing about the HTTP/Livewire request); the action layer
     * reads these so the numbers live in one place.
     */
    /** Per invoice, counted separately for each action (check / sync). */
    public const THROTTLE_PER_INVOICE_SECONDS = 30;

    /** Per user, one counter for both actions together. */
    public const THROTTLE_PER_USER_PER_MINUTE = 20;

    public function __construct(
        private readonly DokuClient $client,
        private readonly SubscriptionActivationService $activation,
    ) {}

    /**
     * Read-only: nothing is written to any Invoice or Subscription.
     *
     * With an $actor the check is audited (no reason required: it changes
     * nothing). The audit result is DOKU's status, or invoice_mismatch /
     * doku_unreachable.
     *
     * @throws DokuPaymentException when DOKU cannot be reached or answers with an error
     */
    public function check(Invoice $invoice, ?User $actor = null): StatusSnapshot
    {
        try {
            $snapshot = $this->snapshot($invoice, $this->client->checkStatus($invoice->invoice_number));
        } catch (\Throwable $e) {
            $this->audit($actor, BillingAuditLog::ACTION_CHECK_STATUS, SyncOutcome::DOKU_UNREACHABLE, $invoice, null, $invoice->status, $invoice->status);

            throw $e;
        }

        $this->audit(
            $actor,
            BillingAuditLog::ACTION_CHECK_STATUS,
            $snapshot->matchesInvoice ? $snapshot->dokuStatus : SyncOutcome::INVOICE_MISMATCH,
            $invoice,
            null,
            $invoice->status,
            $invoice->status,
        );

        return $snapshot;
    }

    /**
     * Apply whatever DOKU says, through process() and nothing else.
     *
     * $reason is validated (10-500 characters) only when an admin $actor
     * is given: an attributed action must say why. A null $actor is the
     * system (scheduler/command) context, which has no reason to give.
     *
     * @throws InvalidArgumentException when an actor is given with a missing/too short/too long reason
     */
    public function sync(Invoice $invoice, ?User $actor = null, ?string $reason = null): SyncOutcome
    {
        if ($actor !== null) {
            $reason = self::validatedReason($reason);
        }

        return $this->apply($invoice, $actor, $reason, true);
    }

    /**
     * System-context entry point for the polling command: never audited (no
     * actor, so no reason either) and, with $enforceMinAge = false, free of
     * the 60-second minimum - the command's `--invoice=` option exists
     * precisely to let an operator at a terminal check a young invoice.
     *
     * This is a separate method, not a flag on {@see sync()}, so the
     * attributed (admin) path can never be told to skip the age limit: sync()
     * hard-wires it.
     */
    public function syncAsSystem(Invoice $invoice, bool $enforceMinAge = true): SyncOutcome
    {
        return $this->apply($invoice, null, null, $enforceMinAge);
    }

    private function apply(Invoice $invoice, ?User $actor, ?string $reason, bool $enforceMinAge): SyncOutcome
    {
        // The caller's model may be a column-restricted table record; the
        // decision below must be made on the real, current row.
        $current = Invoice::query()->whereKey($invoice->getKey())->first();

        if ($current === null) {
            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::UNKNOWN_INVOICE), null, $invoice->invoice_number);
        }

        $old = $current->status;

        if ($current->status !== Invoice::STATUS_PENDING) {
            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::ALREADY_PROCESSED, $old, $old), $current);
        }

        if ($enforceMinAge && ($current->created_at === null || $current->created_at->gt(now()->subSeconds(self::MIN_AGE_SECONDS)))) {
            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::TOO_EARLY, $old, $old), $current);
        }

        try {
            $payload = $this->client->checkStatus($current->invoice_number);
        } catch (DokuPaymentException $e) {
            // Already logged by DokuClient.
            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::DOKU_UNREACHABLE, $old, $old), $current);
        } catch (\Throwable $e) {
            Log::error('DOKU sync: unexpected error', [
                'invoice_number' => $current->invoice_number,
                'exception' => $e->getMessage(),
            ]);

            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::DOKU_UNREACHABLE, $old, $old), $current);
        }

        $dokuStatus = (string) ($payload['transaction']['status'] ?? '');

        if (! in_array($dokuStatus, self::FORWARDED_STATUSES, true)) {
            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::NOT_FORWARDED, $old, $old, $dokuStatus), $current);
        }

        if (($payload['order']['invoice_number'] ?? null) !== $current->invoice_number) {
            Log::warning('DOKU sync: status response for a different invoice', [
                'invoice_number' => $current->invoice_number,
            ]);

            return $this->finish($actor, $reason, new SyncOutcome(SyncOutcome::INVOICE_MISMATCH, $old, $old, $dokuStatus), $current);
        }

        // process() opens its own transaction (a savepoint inside this one).
        // The audit row is written inside the outer transaction so the
        // mutation and its audit commit or roll back together; if process()
        // throws, everything rolls back and the failure is audited below,
        // OUTSIDE the rolled-back transaction, so it is not lost with it.
        try {
            return DB::transaction(function () use ($payload, $current, $old, $dokuStatus, $actor, $reason) {
                $result = $this->activation->process($payload);

                if (! in_array($result, [SubscriptionActivationService::RESULT_PAID, SubscriptionActivationService::RESULT_EXPIRED], true)) {
                    Log::warning('DOKU sync: status rejected by activation service', [
                        'invoice_number' => $current->invoice_number,
                        'result' => $result,
                    ]);
                }

                $new = (string) Invoice::query()->whereKey($current->getKey())->value('status');

                return $this->finish($actor, $reason, new SyncOutcome($this->mapResult($result), $old, $new, $dokuStatus), $current);
            });
        } catch (\Throwable $e) {
            Log::error('DOKU sync: failed to apply status, invoice left unchanged', [
                'invoice_number' => $current->invoice_number,
                'exception' => $e->getMessage(),
            ]);

            $outcome = new SyncOutcome(SyncOutcome::APPLY_FAILED, $old, $old, $dokuStatus);

            try {
                $this->finish($actor, $reason, $outcome, $current);
            } catch (\Throwable $auditFailure) {
                // Never let a broken audit write hide the real outcome.
                Log::error('DOKU sync: could not write audit row for a failed apply', [
                    'invoice_number' => $current->invoice_number,
                    'exception' => $auditFailure->getMessage(),
                ]);
            }

            return $outcome;
        }
    }

    /**
     * Audits a sync outcome (when attributed) and hands it back.
     */
    private function finish(?User $actor, ?string $reason, SyncOutcome $outcome, ?Invoice $invoice, ?string $invoiceNumber = null): SyncOutcome
    {
        $this->audit($actor, BillingAuditLog::ACTION_SYNC, $outcome->result, $invoice, $reason, $outcome->oldStatus, $outcome->newStatus, $invoiceNumber);

        return $outcome;
    }

    private function audit(?User $actor, string $action, string $result, ?Invoice $invoice, ?string $reason, ?string $old, ?string $new, ?string $invoiceNumber = null): void
    {
        if ($actor === null) {
            return;
        }

        BillingAuditLog::record($action, $result, $invoice, $actor, $reason, $old, $new, $invoiceNumber);
    }

    public static function validatedReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A sync reason must be %d-%d characters.',
                self::REASON_MIN_LENGTH,
                self::REASON_MAX_LENGTH
            ));
        }

        return $reason;
    }

    /**
     * Unknown results map to IGNORED rather than leaking a raw string: the
     * outcome vocabulary stays closed.
     */
    private function mapResult(string $result): string
    {
        return match ($result) {
            SubscriptionActivationService::RESULT_PAID => SyncOutcome::PAID,
            SubscriptionActivationService::RESULT_EXPIRED => SyncOutcome::EXPIRED,
            SubscriptionActivationService::RESULT_ALREADY_PROCESSED => SyncOutcome::ALREADY_PROCESSED,
            SubscriptionActivationService::RESULT_AMOUNT_MISMATCH => SyncOutcome::AMOUNT_MISMATCH,
            SubscriptionActivationService::RESULT_REFERENCE_MISMATCH => SyncOutcome::REFERENCE_MISMATCH,
            SubscriptionActivationService::RESULT_UNKNOWN_INVOICE => SyncOutcome::UNKNOWN_INVOICE,
            default => SyncOutcome::IGNORED,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function snapshot(Invoice $invoice, array $payload): StatusSnapshot
    {
        $text = static fn (mixed $value): ?string => is_scalar($value) && (string) $value !== ''
            ? Str::limit((string) $value, 100, '')
            : null;

        $date = $payload['transaction']['date'] ?? null;
        $transactionAt = null;

        if (is_string($date) && $date !== '') {
            try {
                $transactionAt = CarbonImmutable::parse($date);
            } catch (\Throwable) {
                $transactionAt = null;
            }
        }

        $amount = $payload['order']['amount'] ?? null;

        return new StatusSnapshot(
            invoiceNumber: $invoice->invoice_number,
            dokuStatus: (string) $text($payload['transaction']['status'] ?? null),
            dokuAmount: is_numeric($amount) ? (int) $amount : null,
            channel: $text($payload['channel']['id'] ?? null),
            service: $text($payload['service']['id'] ?? null),
            acquirerName: $text($payload['acquirer']['name'] ?? null),
            matchesInvoice: ($payload['order']['invoice_number'] ?? null) === $invoice->invoice_number,
            checkedAt: CarbonImmutable::now(),
            transactionAt: $transactionAt,
        );
    }
}

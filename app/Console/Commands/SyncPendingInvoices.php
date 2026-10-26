<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceSyncer;
use App\Services\SyncOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 9 fallback - DOKU only sends payment notifications to a public HTTPS
 * URL configured in its Back Office, so a missed/undeliverable notification
 * would leave a paid invoice pending forever. This asks DOKU's Check Status
 * API about each pending invoice instead.
 *
 * This command NEVER writes an Invoice itself: it asks App\Services\InvoiceSyncer,
 * which hands whatever DOKU answers to SubscriptionActivationService::process(), the same single door
 * the webhook uses (amount check, original_request_id check when present,
 * lockForUpdate, isFinal idempotency). Only terminal DOKU statuses are
 * forwarded (SUCCESS, EXPIRED); FAILED (ignorable for Checkout, the customer
 * may retry with another method)/PENDING/REDIRECT/TIMEOUT/REFUNDED
 * and anything unknown leave the invoice pending for a later run.
 *
 * Per DOKU's docs the status must not be checked within 60 seconds of a
 * payment, so invoices younger than that are skipped. Invoices more than
 * doku.expire_grace_minutes past their local expires_at are no longer polled: by then
 * subscriptions:expire-invoices has closed (or is about to close) them.
 */
class SyncPendingInvoices extends Command
{
    private const MIN_AGE_SECONDS = InvoiceSyncer::MIN_AGE_SECONDS;

    /**
     * Outcomes counted in the "error(s)" figure: DOKU unreachable, a
     * response about another invoice, or a failure while applying. Every
     * other non-changing outcome (still pending at DOKU, rejected by the
     * activation service, already processed) is not an error here - the
     * syncer has logged the rejected ones.
     */
    private const ERROR_OUTCOMES = [
        SyncOutcome::DOKU_UNREACHABLE,
        SyncOutcome::INVOICE_MISMATCH,
        SyncOutcome::APPLY_FAILED,
    ];

    protected $signature = 'subscriptions:sync-pending-invoices {--invoice= : Sync only this invoice number (skips the age/expiry window)}';

    protected $description = 'Poll DOKU Check Status for pending invoices and apply final results';

    public function handle(InvoiceSyncer $syncer): int
    {
        $query = Invoice::where('status', Invoice::STATUS_PENDING);

        if ($number = $this->option('invoice')) {
            $query->where('invoice_number', $number);
        } else {
            $query->where('created_at', '<=', now()->subSeconds(self::MIN_AGE_SECONDS))
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now()->subMinutes((int) config('doku.expire_grace_minutes')));
                });
        }

        $invoices = $query->orderBy('id')->get();

        if ($number && $invoices->isEmpty()) {
            $this->error("No pending invoice {$number}.");

            return self::FAILURE;
        }

        $changed = 0;
        $errors = 0;

        foreach ($invoices as $invoice) {
            // The candidate query above already applied the age window;
            // `--invoice=` deliberately skips it (and so skips the syncer's
            // own age check too).
            try {
                $outcome = $syncer->syncAsSystem($invoice, enforceMinAge: ! $number);
            } catch (\Throwable $e) {
                // One bad invoice must not stop the rest.
                Log::error('DOKU sync: unexpected error', [
                    'invoice_number' => $invoice->invoice_number,
                    'exception' => $e->getMessage(),
                ]);
                $errors++;

                continue;
            }

            if ($outcome->changedInvoice()) {
                $changed++;
            } elseif (in_array($outcome->result, self::ERROR_OUTCOMES, true)) {
                $errors++;
            }
        }

        $this->info("{$invoices->count()} invoice(s) checked, {$changed} updated, {$errors} error(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Doku\DokuClient;
use App\Services\Doku\DokuPaymentException;
use App\Services\SubscriptionActivationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 9 fallback - DOKU only sends payment notifications to a public HTTPS
 * URL configured in its Back Office, so a missed/undeliverable notification
 * would leave a paid invoice pending forever. This asks DOKU's Check Status
 * API about each pending invoice instead.
 *
 * This command NEVER writes an Invoice itself: whatever DOKU answers is
 * handed to SubscriptionActivationService::process(), the same single door
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
    private const MIN_AGE_SECONDS = 60;

    private const FORWARDED_STATUSES = ['SUCCESS', 'EXPIRED'];

    protected $signature = 'subscriptions:sync-pending-invoices {--invoice= : Sync only this invoice number (skips the age/expiry window)}';

    protected $description = 'Poll DOKU Check Status for pending invoices and apply final results';

    public function handle(DokuClient $client, SubscriptionActivationService $activation): int
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
            try {
                $payload = $client->checkStatus($invoice->invoice_number);
            } catch (DokuPaymentException $e) {
                // Already logged by DokuClient; one bad invoice must not
                // stop the rest.
                $errors++;

                continue;
            } catch (\Throwable $e) {
                Log::error('DOKU sync: unexpected error', [
                    'invoice_number' => $invoice->invoice_number,
                    'exception' => $e->getMessage(),
                ]);
                $errors++;

                continue;
            }

            if (! in_array($payload['transaction']['status'] ?? null, self::FORWARDED_STATUSES, true)) {
                continue;
            }

            // The response must be about the invoice we asked for.
            if (($payload['order']['invoice_number'] ?? null) !== $invoice->invoice_number) {
                Log::warning('DOKU sync: status response for a different invoice', [
                    'invoice_number' => $invoice->invoice_number,
                ]);
                $errors++;

                continue;
            }

            // A failure while applying one invoice (e.g. a DB error during
            // activation) rolls that invoice's transaction back - it stays
            // pending and is retried on the next run - and must not abort
            // the invoices after it.
            try {
                $result = $activation->process($payload);
            } catch (\Throwable $e) {
                Log::error('DOKU sync: failed to apply status, invoice left unchanged', [
                    'invoice_number' => $invoice->invoice_number,
                    'exception' => $e->getMessage(),
                ]);
                $errors++;

                continue;
            }

            if (in_array($result, [
                SubscriptionActivationService::RESULT_PAID,
                SubscriptionActivationService::RESULT_EXPIRED,
            ], true)) {
                $changed++;
            } elseif ($result !== SubscriptionActivationService::RESULT_ALREADY_PROCESSED) {
                Log::warning('DOKU sync: status rejected by activation service', [
                    'invoice_number' => $invoice->invoice_number,
                    'result' => $result,
                ]);
            }
        }

        $this->info("{$invoices->count()} invoice(s) checked, {$changed} updated, {$errors} error(s).");

        return self::SUCCESS;
    }
}

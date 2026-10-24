<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\SubscriptionPlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 9 - the only code path that turns a signature-verified DOKU
 * notification (App\Http\Controllers\DokuWebhookController) into an
 * Invoice status change and, on success, a Subscription activation/
 * extension. Deliberately a separate class from the controller so the
 * controller stays "verify signature, then hand off" and every business
 * rule below is unit-testable without a real HTTP request.
 *
 * Every write happens inside one DB::transaction() with the Invoice row
 * locked via lockForUpdate() first - this is the idempotency and
 * duplicate-notification defense the spec explicitly requires (section B:
 * "HTTP Notification endpoints might occasionally receive the same event
 * more than once", section D). Two notifications for the SAME invoice can
 * never both pass the lock and both see status=pending: the first to
 * acquire the row lock processes it to a final state and commits: the
 * second blocks until the first commits, then reads the now-final status
 * and takes the Invoice::isFinal() short-circuit below, a no-op. This is
 * a real transaction+lock, not merely an `if` in application code (SQLite,
 * used by the test suite, does not have true row-level locking and
 * enforces this via whole-database write serialization instead - see
 * App\Support\SupportSessionContext's own note on this same limitation -
 * so the accompanying tests exercise this by calling process() twice in
 * sequence with the same payload rather than from concurrent threads,
 * which is the same limit every other lockForUpdate() test in this
 * codebase already lives with).
 */
class SubscriptionActivationService
{
    public const RESULT_PAID = 'paid';

    /**
     * A non-final, non-SUCCESS status (FAILED, VOIDED, ...) - deliberately
     * ignored, the invoice stays pending. See process().
     */
    public const RESULT_IGNORED = 'ignored';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_ALREADY_PROCESSED = 'already_processed';

    public const RESULT_UNKNOWN_INVOICE = 'unknown_invoice';

    public const RESULT_REFERENCE_MISMATCH = 'reference_mismatch';

    public const RESULT_AMOUNT_MISMATCH = 'amount_mismatch';

    /**
     * Single entry point for both the signature-verified webhook and the
     * Check Status polling fallback (subscriptions:sync-pending-invoices):
     * both feed a DOKU-shaped payload here, so the amount/reference/
     * idempotency/lock rules exist in exactly one place.
     *
     * Required: order.invoice_number, order.amount, transaction.status.
     * transaction.original_request_id is optional (absent in QRIS
     * notifications) but, when present, must match the invoice.
     *
     * @param  array<string, mixed>  $payload  the decoded, DOKU-originated payload (verified webhook body or Check Status response)
     */
    public function process(array $payload): string
    {
        $invoiceNumber = (string) ($payload['order']['invoice_number'] ?? '');
        $originalRequestId = isset($payload['transaction']['original_request_id'])
            ? (string) $payload['transaction']['original_request_id']
            : null;
        $amount = (int) ($payload['order']['amount'] ?? 0);
        $status = (string) ($payload['transaction']['status'] ?? '');

        return DB::transaction(function () use ($invoiceNumber, $originalRequestId, $amount, $status) {
            // order.invoice_number is the ONLY thing this notification is
            // trusted to identify an invoice by - never an organization_id
            // or amount taken from the payload at face value (CLAUDE.md:
            // "every client-supplied id is re-resolved through an
            // access-scoped query"; a webhook payload is exactly as
            // untrusted as a client-supplied id here).
            $invoice = Invoice::where('invoice_number', $invoiceNumber)->lockForUpdate()->first();

            if ($invoice === null) {
                Log::warning('DOKU webhook: unknown invoice_number', ['invoice_number' => $invoiceNumber]);

                return self::RESULT_UNKNOWN_INVOICE;
            }

            // When transaction.original_request_id is present it must equal
            // the Request-Id WE sent when this invoice's payment was created
            // (spec section B) - proves this notification is about the
            // checkout call this invoice made. It is optional because QRIS
            // notifications do not carry it; without it we rely on the
            // signature (or, for polling, DOKU's own authenticated response)
            // plus invoice_number, amount and the lock/isFinal check below.
            if ($originalRequestId !== null && ! hash_equals((string) $invoice->doku_request_id, $originalRequestId)) {
                Log::warning('DOKU webhook: original_request_id does not match invoice', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoiceNumber,
                ]);

                return self::RESULT_REFERENCE_MISMATCH;
            }

            // The amount DOKU is confirming must match what this invoice
            // was actually created for - never trust the payload's amount
            // on its own to decide what got paid for.
            if ((int) $invoice->amount !== $amount) {
                Log::warning('DOKU webhook: amount does not match invoice', [
                    'invoice_id' => $invoice->id,
                    'invoice_amount' => $invoice->amount,
                    'payload_amount' => $amount,
                ]);

                return self::RESULT_AMOUNT_MISMATCH;
            }

            // Idempotency: an invoice that already reached a final state
            // never transitions again, regardless of what status this
            // (possibly duplicate/replayed) notification carries.
            if ($invoice->isFinal()) {
                return self::RESULT_ALREADY_PROCESSED;
            }

            // Only SUCCESS ever activates a subscription; EXPIRED closes the
            // invoice. Every OTHER status (FAILED, VOIDED, REFUNDED, PENDING,
            // anything undocumented) is IGNORED and leaves the invoice
            // pending: DOKU's Best Practice doc says "For Checkout
            // integrations, you must ignore the transaction.status FAILED",
            // because the customer can switch payment method on the same
            // checkout page and still pay. Marking the invoice failed here
            // would be final (isFinal) and make the later real SUCCESS be
            // dropped as already_processed - money received, no subscription.
            // A never-paid invoice still ends as expired (EXPIRED status or
            // subscriptions:expire-invoices). Still default-deny: nothing
            // other than SUCCESS can grant access.
            if ($status === 'EXPIRED') {
                $invoice->update(['status' => Invoice::STATUS_EXPIRED]);

                return self::RESULT_EXPIRED;
            }

            if ($status !== 'SUCCESS') {
                Log::info('DOKU webhook: non-SUCCESS status ignored, invoice stays pending', [
                    'invoice_id' => $invoice->id,
                    'status' => $status,
                ]);

                return self::RESULT_IGNORED;
            }

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            $this->activateSubscription($invoice);

            return self::RESULT_PAID;
        });
    }

    /**
     * Renewal assumption (FLAGGED - see Fase 8-9 final report "Remaining
     * risks"): nothing in the audited codebase or its tests states what
     * should happen when an Organization buys a plan while its current
     * subscription is still active. This implements the most conservative
     * reading - extend from the existing active subscription's ends_at,
     * never from now() - so a renewal can never shorten the paid-through
     * date a customer already has. An organization with an existing
     * *unlimited* active subscription (ends_at === null) is left unlimited;
     * the new paid period adds nothing measurable on top of "never expires",
     * which is a safe (if commercially wasteful) outcome, not a bug.
     */
    private function activateSubscription(Invoice $invoice): void
    {
        $plan = SubscriptionPlans::find($invoice->plan_code);
        $durationDays = $plan['duration_days'] ?? null;

        if ($durationDays === null) {
            // The plan config changed/removed the plan_code between
            // checkout and payment - cannot know how long to grant. Leave
            // the invoice PAID (the money was genuinely received) but do
            // not fabricate a duration; this is logged for manual handling
            // rather than silently guessing.
            Log::error('DOKU webhook: paid invoice references an unknown plan_code, subscription not extended', [
                'invoice_id' => $invoice->id,
                'plan_code' => $invoice->plan_code,
            ]);

            return;
        }

        $organization = Organization::query()->whereKey($invoice->organization_id)->firstOrFail();

        $activeSubscription = Subscription::where('organization_id', $organization->id)
            ->active()
            ->lockForUpdate()
            ->orderByDesc('ends_at')
            ->first();

        if ($activeSubscription !== null) {
            if ($activeSubscription->ends_at !== null) {
                $activeSubscription->update([
                    'ends_at' => $activeSubscription->ends_at->copy()->addDays($durationDays),
                ]);
            }

            $subscription = $activeSubscription;
        } else {
            $subscription = Subscription::create([
                'organization_id' => $organization->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays($durationDays),
            ]);
        }

        $invoice->update(['subscription_id' => $subscription->id]);
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Organization;
use App\Services\Doku\DokuClient;
use App\Support\SubscriptionPlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fase 8 - turns "this Organization wants to buy this plan" into a pending
 * Invoice row plus a DOKU Checkout redirect URL. The only writer of a
 * brand-new Invoice row in the app; App\Services\SubscriptionActivationService
 * is the only thing that ever transitions one afterward.
 *
 * The whole thing (invoice row + the DOKU HTTP call + saving DOKU's
 * response back onto it) runs inside one DB transaction: if DOKU's call
 * fails for any reason, the half-built invoice is rolled back rather than
 * left as an orphaned pending row with no payment URL a user could ever
 * pay against. The transaction is bounded by DokuClient's own configured
 * timeout (config('doku.timeout'), default 15s) - this is a low-frequency,
 * user-initiated action, not a hot path, so holding the DB connection for
 * one bounded HTTP call is an accepted trade-off here, not an oversight.
 */
class SubscriptionCheckoutService
{
    public function __construct(private DokuClient $client) {}

    /**
     * @throws InvalidArgumentException when $planCode is not a real plan
     * @throws \App\Services\Doku\DokuPaymentException when DOKU's call fails
     */
    public function checkout(Organization $organization, string $planCode): Invoice
    {
        $plan = SubscriptionPlans::find($planCode);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown subscription plan [{$planCode}].");
        }

        return DB::transaction(function () use ($organization, $planCode, $plan) {
            $invoice = Invoice::create([
                'organization_id' => $organization->id,
                'subscription_id' => null,
                'invoice_number' => self::generateInvoiceNumber(),
                'plan_code' => $planCode,
                // Frozen from the same server-side plan as `amount`, so a
                // later config change/removal cannot alter what this
                // invoice grants once it is paid.
                'duration_days' => $plan['duration_days'],
                'plan_name' => $plan['name'],
                // Server-authoritative amount, read straight out of
                // config('subscriptions.plans') above - never anything the
                // caller passed in.
                'amount' => $plan['amount'],
                'currency' => 'IDR',
                'status' => Invoice::STATUS_PENDING,
                'expires_at' => now()->addMinutes((int) config('doku.payment_due_date_minutes')),
            ]);

            $requestId = (string) Str::uuid();

            $response = $this->client->createPayment($requestId, [
                'amount' => $invoice->amount,
                'invoice_number' => $invoice->invoice_number,
                'currency' => $invoice->currency,
            ]);

            $invoice->update([
                'doku_request_id' => $requestId,
                'doku_token_id' => $response['payment']['token_id'],
                'doku_payment_url' => $response['payment']['url'],
            ]);

            return $invoice;
        });
    }

    /**
     * Not DOKU's Request-Id (that is a separate, per-call random UUID sent
     * as a header) - this is order.invoice_number, the identifier a DOKU
     * notification is matched back to an invoice through (spec section B).
     * Uniqueness is enforced by the invoices.invoice_number DB constraint;
     * a random suffix makes a collision astronomically unlikely rather
     * than relying on that constraint to ever actually fire.
     */
    private static function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));
    }
}

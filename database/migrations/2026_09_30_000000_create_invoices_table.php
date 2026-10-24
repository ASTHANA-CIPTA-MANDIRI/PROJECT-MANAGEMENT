<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8-9 (Checkout, Billing & DOKU Webhook) - the payment/billing record
 * App\Models\Subscription deliberately does NOT have (see that table's own
 * migration docblock): a gateway reference, an amount, a currency, and a
 * lifecycle with more states than "active or not".
 *
 * Unlike Subscription, `status` IS a stored column here rather than
 * derived from timestamps. Subscription's derived-state pattern
 * (cancelled_at/ends_at) works because it only has two independent facts
 * to combine. An invoice has a real state machine with invalid
 * transitions to guard against (a webhook must never move a `paid`
 * invoice back to `pending`, and "already reached a final state" needs to
 * be a single cheap check - see App\Models\Invoice::isFinal()) - modelling
 * that as timestamp presence/absence (e.g. a paid_at + failed_at +
 * expired_at combination) would let contradictory combinations exist
 * (both paid_at and failed_at set) with nothing stopping it at the schema
 * level. A flat, application-validated string column is the same shape
 * this codebase already uses for organization_users.role,
 * project_users.role and organization_support_sessions.level - see
 * App\Models\Invoice::STATUSES.
 *
 * organization_id cascadeOnDelete: same reasoning as
 * subscriptions.organization_id - an invoice for an organization that no
 * longer exists is meaningless.
 *
 * subscription_id nullable + nullOnDelete: an invoice is created *before*
 * a Subscription row necessarily exists (a brand new subscriber has no
 * Subscription row yet at checkout time) and is only linked to the
 * Subscription it activated/extended once the DOKU webhook actually
 * verifies payment (App\Services\SubscriptionActivationService) - never
 * at checkout time, to avoid linking an invoice to a Subscription row that
 * might change (get extended by a different, concurrent invoice) between
 * checkout and payment confirmation. nullOnDelete (not cascade): an
 * invoice is a billing history record that should outlive the Subscription
 * row it once activated, mirroring why projects.organization_id is
 * nullOnDelete rather than cascade.
 *
 * doku_request_id (the Request-Id we sent to DOKU at checkout) and
 * doku_reference (transaction.original_request_id DOKU echoes back in the
 * webhook notification) are deliberately two separate columns rather than
 * one, even though a valid notification's doku_reference must equal this
 * invoice's own doku_request_id - see
 * SubscriptionActivationService::process(), which rejects a notification
 * whose original_request_id does not match what this invoice actually
 * sent, rather than trusting order.invoice_number alone to prove the
 * notification belongs to this checkout.
 *
 * No unique constraint tying "one active/pending invoice per
 * organization" - same deliberate omission Subscription's own migration
 * documents (multiple concurrent invoices for one organization are
 * possible, e.g. a double-submitted checkout) and Fase 8-9 only needs
 * per-invoice idempotency (invoice_number is unique, and
 * SubscriptionActivationService locks + checks isFinal() before any
 * write), not a global one-per-organization invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            // The identifier WE generate and send to DOKU as
            // order.invoice_number - the single field a DOKU notification is
            // matched back to an invoice through (spec section B).
            $table->string('invoice_number')->unique();

            // Which config('subscriptions.plans') entry this invoice was for
            // - read back at webhook time to know how many days to extend
            // the Subscription by (DOKU's notification carries no plan/
            // duration information of its own).
            $table->string('plan_code');

            // IDR, integer, no decimals - matches DOKU's order.amount shape
            // and is always sourced from config('subscriptions.plans'), never
            // client input (App\Http\Controllers\SubscriptionCheckoutController).
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('IDR');

            $table->string('status')->default('pending');

            $table->string('doku_request_id')->nullable();
            $table->string('doku_reference')->nullable();
            $table->string('doku_token_id')->nullable();
            $table->text('doku_payment_url')->nullable();

            // Local timeout clock for the pending -> expired transition
            // (spec section B: DOKU does not reliably send an "expired"
            // status for every payment method, so expiry is handled
            // app-side from payment.payment_due_date, not from a DOKU
            // notification field). See App\Console\Commands\ExpirePendingInvoices.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // Every read this phase adds
            // (App\Services\SubscriptionActivationService,
            // App\Console\Commands\ExpirePendingInvoices) filters by
            // organization_id and/or status - matching the same composite
            // index shape subscriptions_org_cancelled_idx already uses.
            $table->index(['organization_id', 'status'], 'invoices_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

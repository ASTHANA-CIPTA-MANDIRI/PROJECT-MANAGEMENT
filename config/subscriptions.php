<?php

/**
 * Fase 8-9 (Checkout & Billing) plan catalog. This is the single
 * server-side source of truth for what a plan costs and how long it
 * lasts - a checkout request only ever names a plan *code*
 * (App\Http\Controllers\SubscriptionCheckoutController), the amount sent
 * to DOKU is always read back out of this file, never out of client input.
 *
 * IMPORTANT / FINDING FOR THE USER: the amount and duration_days values
 * below are PLACEHOLDERS. Nothing in the audited codebase (Fase 7 or
 * earlier) defines real pricing, currency other than IDR, or plan naming -
 * this is business/pricing data, not something derivable from the DOKU
 * spec or existing code, so it was not guessed beyond "something has to
 * exist for the amount-from-server requirement to be satisfiable at all".
 * Replace with real figures before this goes anywhere near production; see
 * the Fase 8-9 final report.
 */
return [

    /*
     * Whether the Organization Settings "Subscription" panel offers plans,
     * prices and buy buttons at all. The prices below are placeholders, so
     * the default is ON everywhere except production; production only turns
     * it on when SUBSCRIPTIONS_CHECKOUT_ENABLED=true is set explicitly.
     * UI-only switch: POST /organizations/subscribe itself is not gated by it.
     */
    'checkout_enabled' => (bool) env('SUBSCRIPTIONS_CHECKOUT_ENABLED', env('APP_ENV') !== 'production'),

    'plans' => [

        'monthly' => [
            'name' => 'Monthly',
            'amount' => 150000, // IDR, no decimals (DOKU order.amount is an integer)
            'duration_days' => 30,
        ],

        'yearly' => [
            'name' => 'Yearly',
            'amount' => 1500000,
            'duration_days' => 365,
        ],

    ],

];

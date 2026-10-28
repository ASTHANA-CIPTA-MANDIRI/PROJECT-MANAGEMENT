<?php

/**
 * Fase 8-9 (Checkout & Billing) plan catalog. The live source of truth for
 * what a plan costs and how long it lasts is the `subscription_plans` table
 * (edited by a Super Admin on the Plans page), read through
 * App\Support\SubscriptionPlans - a checkout request only ever names a plan
 * *code* (App\Http\Controllers\SubscriptionCheckoutController), the amount
 * sent to DOKU is always read back out of the database, never out of client
 * input. The `plans` array below is ONLY the seed: the create_subscription_plans
 * migration copies it into an empty table once, and nothing reads it at
 * runtime afterwards (editing it later changes nothing).
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

    /*
     * Paywall: when on, a signed-in member of an Organization whose trial has
     * ended (and that has no active subscription) is redirected from every
     * full-page panel navigation to the billing page
     * (App\Http\Middleware\RedirectLockedOrganizationToBilling). Same default
     * as checkout_enabled (on outside production, explicit opt-in in
     * production). It only takes effect together with checkout_enabled:
     * redirecting people to a page that cannot sell them anything would trap
     * them, so with checkout off the paywall counts as off and behaviour is
     * the pre-paywall one. The combined check lives in RedirectLockedOrganizationToBilling::paywallActive().
     */
    'paywall_enabled' => (bool) env('SUBSCRIPTIONS_PAYWALL_ENABLED', env('APP_ENV') !== 'production'),

    /*
     * Bounds the Plans page enforces when a Super Admin creates or edits a plan
     * (App\Services\PlanManager). min_amount is a CONSERVATIVE guess, not a
     * documented DOKU limit: DOKU's real minimum order.amount per payment
     * channel is not known to this codebase and MUST be confirmed with DOKU
     * (and raised here) before prices are lowered towards it. max_amount is
     * only a typo guard (an extra zero), duration is capped at ~10 years.
     */
    'min_amount' => (int) env('SUBSCRIPTIONS_MIN_AMOUNT', 1000),
    'max_amount' => 1_000_000_000,
    'min_duration_days' => 1,
    'max_duration_days' => 3660,

    // Seed data for the subscription_plans table only - see the docblock above.
    'plans' => [

        'monthly' => [
            'name' => 'Monthly',
            'amount' => 150000, // IDR, no decimals (DOKU order.amount is an integer)
            'duration_days' => 30,
            'sort_order' => 1, // shown first (lower numbers first)
        ],

        'yearly' => [
            'name' => 'Yearly',
            'amount' => 1500000,
            'duration_days' => 365,
            'sort_order' => 2,
        ],

    ],

];

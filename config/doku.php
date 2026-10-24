<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DOKU credentials (DOKU Back Office)
    |--------------------------------------------------------------------------
    |
    | client_id/secret_key are merchant credentials issued by DOKU. How to
    | actually provision real sandbox/production values is not covered by
    | the verified spec this integration was built against - left blank
    | here on purpose, see Fase 8-9 final report "missing info".
    */
    'client_id' => env('DOKU_CLIENT_ID'),
    'secret_key' => env('DOKU_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Sandbox by default everywhere except when explicitly flipped to
    | production - mirrors SESSION_SECURE_COOKIE's "safe default, opt into
    | the dangerous one" shape in .env.example.
    */
    'production' => (bool) env('DOKU_PRODUCTION', false),

    /*
    |--------------------------------------------------------------------------
    | Checkout page hosts (CSP form-action)
    |--------------------------------------------------------------------------
    |
    | Hosts of the DOKU-hosted checkout page (the host of payment.url).
    | SubscriptionCheckoutController redirects the browser there right after
    | the POST /organizations/subscribe form submit, and Chrome applies the
    | CSP `form-action` directive to that redirect too - so these hosts are
    | added to form-action in config/security.php (and nowhere else).
    |
    | Verified: sandbox = staging.doku.com (observed in a real sandbox
    | response; DOKU's docs sample shows sandbox.doku.com, which is not what
    | the sandbox returns). NOT verified: the production host - DOKU's public
    | docs never state it, so it is deliberately not guessed. Production
    | therefore defaults to an empty list and MUST be set through
    | DOKU_CHECKOUT_HOSTS (comma-separated bare hostnames) before go-live.
    | Sandbox host is never allowed in production mode, and vice versa.
    */
    'checkout_hosts' => (function (): array {
        $configured = trim((string) env('DOKU_CHECKOUT_HOSTS', ''));

        $hosts = $configured !== ''
            ? explode(',', $configured)
            : ((bool) env('DOKU_PRODUCTION', false) ? [] : ['staging.doku.com']);

        // Bare hostnames only: this ends up inside a CSP header, so reject
        // wildcards, schemes, paths, quotes and semicolons rather than trust env.
        return array_values(array_unique(array_filter(
            array_map(fn ($h) => strtolower(trim($h)), $hosts),
            fn ($h) => (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $h)
        )));
    })(),

    'sandbox_base_url' => 'https://api-sandbox.doku.com',
    'production_base_url' => 'https://api.doku.com',

    // DOKU Checkout create-payment path (spec section A). Also the
    // Request-Target used when signing that outbound call (spec section C).
    'checkout_path' => '/checkout/v1/payment',

    // Check Status API path prefix; the invoice_number is appended (that
    // full path is also the Request-Target when signing - a GET, no Digest).
    'status_path' => '/orders/v1/status',

    // Minutes until a DOKU Checkout payment page expires
    // (payment.payment_due_date, spec section A - default DOKU itself uses
    // when this is omitted is also 60).
    'payment_due_date_minutes' => (int) env('DOKU_PAYMENT_DUE_DATE_MINUTES', 60),

    // OPT-IN. Sent as additional_info.override_notification_url on create-payment
    // (DOKU "Override Notification URL" doc): DOKU then notifies THIS URL instead
    // of the one configured in Back Office. Per the doc the Back Office URL must
    // still be configured, the override path must equal that configured path
    // (/api/doku/notifications) and only the domain/subdomain may differ - handy
    // when a dev tunnel's domain changes on every reconnect. Must be https with
    // exactly that path; anything else is not sent (logged). Empty = field not sent.
    'notification_url_override' => env('DOKU_NOTIFICATION_URL_OVERRIDE'),

    // Minutes AFTER an invoice's expires_at before subscriptions:expire-invoices
    // closes it locally, and how long subscriptions:sync-pending-invoices keeps
    // polling DOKU for it. Keeps a last-minute payment catchable by polling.
    'expire_grace_minutes' => (int) env('DOKU_EXPIRE_GRACE_MINUTES', 30),

    'timeout' => (float) env('DOKU_TIMEOUT', 15),
    'connect_timeout' => (float) env('DOKU_CONNECT_TIMEOUT', 5),

];

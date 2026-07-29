<?php

$trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Internal Services IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Requests to routes protected by the `internal` middleware are only
    | allowed from these IPs. Supports single addresses and CIDR ranges, e.g.
    | INTERNAL_IP_WHITELIST="127.0.0.1,10.0.0.0/8,192.168.1.0/24"
    |
    | An empty list blocks everyone (fail closed).
    |
    */

    'ip_whitelist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INTERNAL_IP_WHITELIST', '127.0.0.1,::1'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Load balancers / reverse proxies to trust so $request->ip() reflects the
    | real client (needed for the IP whitelist above). Set TRUSTED_PROXIES to a
    | comma-separated list of IPs/CIDRs, or "*" to trust all forwarding proxies
    | (only when the app is reachable *exclusively* through them).
    |
    | Empty = trust none (safe default when not behind a proxy).
    |
    */

    'trusted_proxies' => $trustedProxies === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),

];

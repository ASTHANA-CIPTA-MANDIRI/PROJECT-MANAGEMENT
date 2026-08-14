<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Jira Hosts
    |--------------------------------------------------------------------------
    |
    | Optional allowlist for the host the Jira import wizard is allowed to call.
    | Supports exact hostnames and wildcard suffixes, e.g.
    | JIRA_ALLOWED_HOSTS="*.atlassian.net,jira.example.com"
    |
    | An empty list allows any *public* host — the private/reserved IP ranges
    | below are always refused, allowlist or not.
    |
    */

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('JIRA_ALLOWED_HOSTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allow Plain HTTP
    |--------------------------------------------------------------------------
    |
    | Jira credentials are sent as a Basic auth header, so https is required by
    | default. Only enable this for a self-hosted instance on a trusted network.
    |
    */

    'allow_insecure_scheme' => (bool) env('JIRA_ALLOW_HTTP', false),

    /*
    |--------------------------------------------------------------------------
    | Blocked IP Ranges (SSRF guard)
    |--------------------------------------------------------------------------
    |
    | The host is resolved before connecting and refused when any of its
    | addresses falls inside these ranges. This keeps the wizard from being
    | used to reach loopback services, the cloud metadata endpoint
    | (169.254.169.254) or anything else on the internal network.
    |
    */

    'blocked_ip_ranges' => [
        // IPv4
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // IPv6
        '::/128',
        '::1/128',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | Explicit timeouts (seconds) so an unresponsive host cannot hold a request
    | worker open indefinitely.
    |
    */

    'timeout' => (float) env('JIRA_TIMEOUT', 10),

    'connect_timeout' => (float) env('JIRA_CONNECT_TIMEOUT', 5),

];

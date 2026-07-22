<?php

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

];

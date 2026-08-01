<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Sent as the `Content-Security-Policy` header. The default is compatible
    | with Filament/Livewire/Alpine (which need 'unsafe-inline' + 'unsafe-eval')
    | and the app's external hosts (tippy CSS on unpkg, avatars/images over
    | https, Pusher over wss). Tighten it as you can; set CSP_ENABLED=false to
    | drop the header entirely (e.g. while debugging a blocked resource).
    |
    */

    'csp_enabled' => (bool) env('CSP_ENABLED', true),

    'csp' => env('CONTENT_SECURITY_POLICY', implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "img-src 'self' data: https:",
        "font-src 'self' data:",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline' https://unpkg.com",
        "connect-src 'self' https: wss:",
    ])),

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Only emitted on HTTPS requests, so local HTTP development is unaffected.
    |
    */

    'hsts' => env('HSTS_HEADER', 'max-age=31536000; includeSubDomains'),

];

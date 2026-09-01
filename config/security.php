<?php

// A variable that is present but blank in .env ("CONTENT_SECURITY_POLICY=")
// reaches env() as an empty string, not null, so it would win over the default
// passed to env() and then — being falsy — make the middleware drop the header
// altogether. Blank means "not configured", so resolve these by hand instead.
$csp = trim((string) env('CONTENT_SECURITY_POLICY', ''));

// Not cast to string first: env() already turns "false" into a boolean, and
// (string) false is "" — which would be indistinguishable from a blank value.
$cspEnabled = env('CSP_ENABLED', true);

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Sent as the `Content-Security-Policy` header. The default is compatible
    | with Filament/Livewire/Alpine (which need 'unsafe-inline' + 'unsafe-eval')
    | and the app's external hosts (avatars/images over https, Pusher over wss).
    | Scripts and styles are served from this origin only — everything the panel
    | needs is compiled into the Vite bundle. Tighten it as you can; set
    | CSP_ENABLED=false to drop the header entirely (e.g. while debugging a
    | blocked resource). Leaving CONTENT_SECURITY_POLICY blank (or omitting it)
    | keeps the default below; only a non-empty value overrides it.
    |
    */

    'csp_enabled' => $cspEnabled === '' ? true : filter_var($cspEnabled, FILTER_VALIDATE_BOOLEAN),

    'csp' => $csp !== '' ? $csp : implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "img-src 'self' data: https:",
        "font-src 'self' data:",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "connect-src 'self' https: wss:",
    ]),

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

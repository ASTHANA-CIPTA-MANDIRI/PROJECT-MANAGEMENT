<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Only the methods the API actually exposes (see routes/api.php): every
    // route is GET or POST, nothing accepts PUT/PATCH/DELETE. OPTIONS is
    // listed for clarity; preflight requests are answered by the CORS
    // middleware itself regardless of this list.
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    // No origin by default: with supports_credentials false, a bare '*' here
    // does not leak session cookies, but it still lets any website read the
    // API response body once it holds a bearer token - e.g. one leaked to a
    // malicious third-party app. Set CORS_ALLOWED_ORIGINS in the environment
    // (comma-separated) to the actual frontend origin(s) that call this API
    // from a browser; leave unset to allow none.
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

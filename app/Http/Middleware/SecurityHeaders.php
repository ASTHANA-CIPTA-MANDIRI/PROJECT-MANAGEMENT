<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Adds hardening response headers to every request: clickjacking &
 * MIME-sniffing protection, a referrer policy, a locked-down permissions
 * policy, HSTS (HTTPS only), and a configurable Content-Security-Policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Always-safe headers (never break the app). Don't override any a
        // response deliberately set.
        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
            'Permissions-Policy' => 'browsing-topics=(), geolocation=(), camera=(), microphone=()',
        ];

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // HSTS only makes sense over HTTPS.
        if ($request->secure() && ($hsts = config('security.hsts'))) {
            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        if (config('security.csp_enabled') && ($csp = config('security.csp'))) {
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', $csp);
            }
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Restricts a route to internal services by checking the client IP against a
 * configured whitelist (single addresses or CIDR ranges). Fails closed: an
 * empty whitelist rejects every request.
 */
class EnsureIpIsWhitelisted
{
    public function handle(Request $request, Closure $next)
    {
        $whitelist = config('internal.ip_whitelist', []);

        if (! IpUtils::checkIp($request->ip(), $whitelist)) {
            throw new AccessDeniedHttpException('Your IP is not allowed to access this resource.');
        }

        return $next($request);
    }
}

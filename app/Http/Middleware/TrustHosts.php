<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * APP_URL and its subdomains are always trusted. Deployments reachable
     * under more than one name (a bare domain plus a vanity/CDN host, a
     * staging alias) add the extras through TRUSTED_HOSTS instead of having to
     * override this method.
     *
     * @return array<int, string|null>
     */
    public function hosts()
    {
        return array_merge(
            [$this->allSubdomainsOfApplicationUrl()],
            array_map(
                fn (string $host) => '^'.preg_quote($host).'$',
                config('internal.trusted_hosts', [])
            )
        );
    }
}

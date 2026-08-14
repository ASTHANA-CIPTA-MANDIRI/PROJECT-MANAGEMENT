<?php

namespace App\Support;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Guards the user-supplied Jira host before it becomes a Guzzle `base_uri`.
 *
 * Without this the import wizard is an open SSRF proxy: any host reachable
 * from the server (loopback services, 169.254.169.254, the internal network)
 * could be requested and its response reflected back into the panel. Hosts
 * must therefore use https, may be restricted to a configured allowlist, and
 * are resolved so addresses in private/reserved ranges can be refused.
 *
 * Fails closed — anything unparseable or unresolvable is refused.
 */
class JiraHost
{
    /** @var callable|null Hostname resolver override, for tests. */
    private static $resolver = null;

    /**
     * Swap the DNS resolver (a callable taking a hostname and returning IPs).
     * Pass null to restore the real one.
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function isSafe(?string $host): bool
    {
        try {
            self::sanitize((string) $host);

            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Validate the host and return the bare `scheme://host[:port]` origin to
     * use as a base uri. Paths, queries and credentials are dropped: every
     * call site requests an absolute `/rest/api/...` path anyway.
     *
     * @throws InvalidArgumentException when the host may not be called.
     */
    public static function sanitize(string $host): string
    {
        $host = trim($host);
        $parts = parse_url($host);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException(__('Enter the full Jira url, including https://'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('The Jira url must not contain credentials.'));
        }

        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = config('jira.allow_insecure_scheme') ? ['https', 'http'] : ['https'];

        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException(__('The Jira url must use https.'));
        }

        $hostname = strtolower(trim($parts['host'], '[]'));

        if (! self::isAllowlisted($hostname)) {
            throw new InvalidArgumentException(__('This Jira host is not on the list of allowed hosts.'));
        }

        $addresses = self::resolve($hostname);

        if ($addresses === []) {
            throw new InvalidArgumentException(__('The Jira host could not be resolved.'));
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                throw new InvalidArgumentException(__('The Jira host resolves to an internal address and cannot be used.'));
            }
        }

        return $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function isAllowlisted(string $hostname): bool
    {
        $allowed = config('jira.allowed_hosts', []);

        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $pattern) {
            $pattern = strtolower(trim($pattern));

            if ($pattern === $hostname) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && str_ends_with($hostname, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $hostname): array
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return [$hostname];
        }

        if (self::$resolver !== null) {
            return array_values((array) call_user_func(self::$resolver, $hostname));
        }

        $addresses = gethostbynamel($hostname) ?: [];

        foreach (@dns_get_record($hostname, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isBlocked(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }

        // ::ffff:127.0.0.1 and friends are IPv4 in an IPv6 coat.
        if (stripos($address, '::ffff:') === 0 && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $address = substr($address, 7);
        }

        return IpUtils::checkIp($address, config('jira.blocked_ip_ranges', []));
    }
}

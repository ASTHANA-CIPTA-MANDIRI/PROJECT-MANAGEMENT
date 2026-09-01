<?php

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

/**
 * A Host header the app does not own must not be echoed back into generated
 * URLs (password resets, signed links, cache keys). TrustHosts is what stops
 * that, so it has to stay in the global stack — and it has to trust APP_URL
 * plus whatever extra names the deployment answers to, and nothing else.
 *
 * The middleware deliberately no-ops in `local` and under tests
 * (Illuminate\Http\Middleware\TrustHosts::shouldSpecifyTrustedHosts), so the
 * patterns are asserted directly rather than through an HTTP round trip.
 */
class TrustedHostsTest extends TestCase
{
    /**
     * Symfony compiles each pattern as `{<pattern>}i` before matching the
     * incoming Host header; mirror that here.
     */
    private function matchesAnyPattern(string $host): bool
    {
        foreach (array_filter(app(TrustHosts::class)->hosts()) as $pattern) {
            if (preg_match(sprintf('{%s}i', $pattern), $host)) {
                return true;
            }
        }

        return false;
    }

    public function test_trust_hosts_is_registered_in_the_global_middleware_stack(): void
    {
        $middleware = (fn () => $this->middleware)->call(app(Kernel::class));

        $this->assertContains(TrustHosts::class, $middleware);
    }

    public function test_the_application_url_and_its_subdomains_are_trusted(): void
    {
        config(['app.url' => 'https://rencanakan.test']);

        $this->assertTrue($this->matchesAnyPattern('rencanakan.test'));
        $this->assertTrue($this->matchesAnyPattern('app.rencanakan.test'));
    }

    public function test_an_unknown_host_is_not_trusted(): void
    {
        config(['app.url' => 'https://rencanakan.test', 'internal.trusted_hosts' => []]);

        $this->assertFalse($this->matchesAnyPattern('evil.example.com'));
        // Suffix attack: "rencanakan.test.evil.com" must not pass as a subdomain.
        $this->assertFalse($this->matchesAnyPattern('rencanakan.test.evil.com'));
    }

    public function test_extra_hosts_from_configuration_are_trusted_exactly(): void
    {
        config([
            'app.url' => 'https://rencanakan.test',
            'internal.trusted_hosts' => ['cdn.example.com'],
        ]);

        $this->assertTrue($this->matchesAnyPattern('cdn.example.com'));
        // The extra host is anchored, not a subdomain wildcard.
        $this->assertFalse($this->matchesAnyPattern('evil.cdn.example.com'));
    }

    public function test_trusted_hosts_config_is_empty_by_default(): void
    {
        $this->assertSame([], config('internal.trusted_hosts'));
    }
}

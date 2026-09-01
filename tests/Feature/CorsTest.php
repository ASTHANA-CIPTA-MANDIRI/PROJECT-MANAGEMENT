<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config/cors.php used to allow every origin ('*') on api/* and
 * sanctum/csrf-cookie. supports_credentials is false, so session cookies
 * never rode along - but any website could still read the API response body
 * once it held a bearer token (e.g. one leaked to a malicious third-party
 * app), and allowed_methods/allowed_headers being '*' gave up a free layer
 * of defense for no reason: the API only ever exposes GET/POST.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private function url(): string
    {
        return '/api/internal/health';
    }

    public function test_no_origin_is_allowed_by_default(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->get($this->url(), ['Origin' => 'https://evil.example.com']);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_configured_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);

        $response = $this->get($this->url(), ['Origin' => 'https://app.example.com']);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    }

    public function test_an_unlisted_origin_is_still_refused_once_others_are_configured(): void
    {
        // With a single allowed origin, the CORS library echoes it back
        // unconditionally and relies on the browser to reject a mismatch
        // against its own Origin - so this needs two configured origins to
        // exercise the dynamic per-request check.
        config(['cors.allowed_origins' => ['https://app.example.com', 'https://admin.example.com']]);

        $response = $this->get($this->url(), ['Origin' => 'https://evil.example.com']);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_credentials_are_never_shared_across_origins(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);

        $response = $this->get($this->url(), ['Origin' => 'https://app.example.com']);

        $response->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_allowed_methods_are_limited_to_what_the_api_exposes(): void
    {
        // Every route in routes/api.php is GET or POST; nothing accepts
        // PUT/PATCH/DELETE, so the CORS layer should not advertise them.
        $this->assertSame(['GET', 'POST', 'OPTIONS'], config('cors.allowed_methods'));
    }

    /**
     * CORS_ALLOWED_ORIGINS is unset in the test environment (phpunit.xml), so
     * this is the config file's actual explode()/array_filter() resolving a
     * blank env value - not a re-implementation of that logic.
     */
    public function test_an_unset_env_var_resolves_to_no_allowed_origins(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));
    }
}

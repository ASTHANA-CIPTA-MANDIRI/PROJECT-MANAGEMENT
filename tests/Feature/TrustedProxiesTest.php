<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * $request->ip() must reflect the real client behind a trusted proxy (so the
 * internal IP whitelist judges the right address), and must ignore a spoofed
 * X-Forwarded-For when no proxy is trusted.
 */
class TrustedProxiesTest extends TestCase
{
    private function defineIpRoute(): void
    {
        Route::get('/_test_ip', fn () => request()->ip())
            ->middleware(TrustProxies::class);
    }

    public function test_forwarded_ip_is_used_when_the_proxy_is_trusted(): void
    {
        config(['internal.trusted_proxies' => '*']);
        $this->defineIpRoute();

        $this->get('/_test_ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertSee('203.0.113.9');
    }

    public function test_forwarded_ip_is_ignored_when_no_proxy_is_trusted(): void
    {
        config(['internal.trusted_proxies' => []]);
        $this->defineIpRoute();

        $body = $this->get('/_test_ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->getContent();

        $this->assertNotSame('203.0.113.9', $body, 'a spoofed forwarded IP must be ignored');
    }
}

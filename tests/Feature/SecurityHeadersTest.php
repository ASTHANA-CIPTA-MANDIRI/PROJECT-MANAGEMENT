<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function url(): string
    {
        return route('filament.auth.login');
    }

    public function test_hardening_headers_are_present_on_every_response(): void
    {
        $res = $this->get($this->url());

        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('X-XSS-Protection', '0');
        $this->assertNotNull($res->headers->get('Permissions-Policy'));
        $this->assertNotNull($res->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get($this->url())->assertHeaderMissing('Strict-Transport-Security');

        $secure = str_replace('http://', 'https://', $this->url());
        $this->assertNotNull(
            $this->get($secure)->headers->get('Strict-Transport-Security')
        );
    }

    public function test_csp_can_be_disabled_via_config(): void
    {
        config(['security.csp_enabled' => false]);

        $this->get($this->url())->assertHeaderMissing('Content-Security-Policy');
    }
}

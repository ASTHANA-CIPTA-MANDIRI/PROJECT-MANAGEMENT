<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
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

    /**
     * Scripts and styles are compiled into the Vite bundle, so the policy must
     * not whitelist any external host: a CDN allowed here is a CDN that can
     * inject markup into every panel page, login included.
     */
    public function test_csp_allows_scripts_and_styles_from_this_origin_only(): void
    {
        $csp = $this->get($this->url())->headers->get('Content-Security-Policy');

        $directives = collect(explode(';', $csp))
            ->mapWithKeys(function (string $directive) {
                [$name, $sources] = array_pad(explode(' ', trim($directive), 2), 2, '');

                return [$name => $sources];
            });

        $this->assertSame("'self' 'unsafe-inline'", $directives['style-src']);
        $this->assertSame("'self' 'unsafe-inline' 'unsafe-eval'", $directives['script-src']);
    }

    public function test_panel_registers_no_third_party_assets(): void
    {
        // Render a panel page first so the theme/scripts registered inside
        // `Filament::serving()` are resolved too, not just the boot-time ones.
        $this->get($this->url());

        $assets = collect(Filament::getStyles())
            ->merge(Filament::getScripts())
            ->merge([Filament::getThemeLink()])
            ->map(fn ($asset) => (string) $asset)
            ->implode(' ');

        preg_match_all('#https?://[^\s"\'>]+#i', $assets, $matches);

        $firstPartyHosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url(url('/'), PHP_URL_HOST),
        ]);

        foreach ($matches[0] as $assetUrl) {
            $host = parse_url($assetUrl, PHP_URL_HOST);

            $this->assertContains(
                $host,
                $firstPartyHosts,
                "Panel asset loaded from third-party host {$host}; bundle it through Vite instead."
            );
        }
    }
}

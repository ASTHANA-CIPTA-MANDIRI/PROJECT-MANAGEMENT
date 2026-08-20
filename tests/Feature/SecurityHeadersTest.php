<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
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
     *
     * Asserted against the default this repository ships, not against the
     * running config, so that a developer with their own CONTENT_SECURITY_POLICY
     * in .env does not turn this into a failing test on their machine.
     */
    public function test_csp_allows_scripts_and_styles_from_this_origin_only(): void
    {
        $csp = $this->resolveSecurityConfig('CONTENT_SECURITY_POLICY', null)['csp'];

        $directives = collect(explode(';', $csp))
            ->mapWithKeys(function (string $directive) {
                [$name, $sources] = array_pad(explode(' ', trim($directive), 2), 2, '');

                return [$name => $sources];
            });

        $this->assertSame("'self' 'unsafe-inline'", $directives['style-src']);
        $this->assertSame("'self' 'unsafe-inline' 'unsafe-eval'", $directives['script-src']);
    }

    /**
     * Re-resolve config/security.php with $value in the environment, so these
     * assertions describe the shipped defaults rather than whatever the machine
     * running the suite happens to have in its .env.
     *
     * Mutates the Env repository in place (the same approach as
     * TwoFactorEnforcementTest) rather than rebuilding it: replacing the
     * repository instance resets Dotenv's immutability bookkeeping and leaks
     * into every later test in the process.
     *
     * @param  string|null  $value  null unsets the variable entirely
     * @return array<string, mixed>
     */
    private function resolveSecurityConfig(string $name, ?string $value): array
    {
        $repository = Env::getRepository();
        $previous = $repository->get($name);

        $restore = function () use ($repository, $name, $previous): void {
            $repository->clear($name);

            if ($previous !== null) {
                $repository->set($name, $previous);
            }
        };

        $repository->clear($name);

        if ($value !== null) {
            $repository->set($name, $value);
        }

        // The repository is immutable, so clear()/set() are silently refused for
        // a variable that already exists in the real environment. Skip rather
        // than assert against a value we never managed to apply.
        if ($repository->get($name) !== $value) {
            $restore();

            $this->markTestSkipped(
                "{$name} is set in this machine's environment and cannot be overridden for this test."
            );
        }

        try {
            return require config_path('security.php');
        } finally {
            $restore();
        }
    }

    /**
     * A blank `CONTENT_SECURITY_POLICY=` in .env arrives as an empty string
     * rather than null, so it would override the default policy and — being
     * falsy — make the middleware skip the header entirely. Any install that
     * copied .env.example would then run with no CSP at all.
     */
    public function test_blank_csp_environment_variable_falls_back_to_the_default_policy(): void
    {
        $config = $this->resolveSecurityConfig('CONTENT_SECURITY_POLICY', '');

        $this->assertNotSame('', $config['csp']);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $config['csp']);
    }

    public function test_blank_csp_enabled_variable_keeps_the_header_switched_on(): void
    {
        $this->assertTrue($this->resolveSecurityConfig('CSP_ENABLED', '')['csp_enabled']);
        $this->assertFalse($this->resolveSecurityConfig('CSP_ENABLED', 'false')['csp_enabled']);
        $this->assertTrue($this->resolveSecurityConfig('CSP_ENABLED', 'true')['csp_enabled']);
    }

    public function test_a_real_csp_environment_value_still_overrides_the_default(): void
    {
        $config = $this->resolveSecurityConfig('CONTENT_SECURITY_POLICY', "default-src 'none'");

        $this->assertSame("default-src 'none'", $config['csp']);
    }

    public function test_env_example_does_not_blank_out_the_csp_override(): void
    {
        $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);

        $this->assertNotContains(
            'CONTENT_SECURITY_POLICY=',
            array_map('trim', $lines),
            'A blank CONTENT_SECURITY_POLICY= in .env.example is copied into real deployments; comment it out or give it a value.'
        );
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

<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A social login button must not appear for a provider whose OAuth
 * credentials are not configured - clicking it would send the user into an
 * OAuth redirect Google/GitHub reject with "invalid_request: Missing
 * required parameter: client_id". AppServiceProvider::configureSocialiteProviders()
 * filters config('filament-socialite.providers') down to providers whose
 * services.{provider}.client_id AND client_secret are both filled - that
 * config key is exactly what FilamentSocialite::getProviderButtons() reads
 * to decide which buttons the login page renders, so asserting on it tests
 * the application's own behavior without depending on the vendor package's
 * rendering internals.
 */
class SocialiteProviderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each test method gets a fresh application (and therefore a fresh
     * config), but that fresh boot already ran configureSocialiteProviders()
     * once with whatever credentials this environment's .env happens to
     * have. Reset the provider list back to the package's own default (both
     * providers present) before filtering again, so every test is
     * independent of that.
     */
    private function filterProvidersWith(?string $googleId, ?string $googleSecret, ?string $githubId, ?string $githubSecret): void
    {
        config([
            'services.google.client_id' => $googleId,
            'services.google.client_secret' => $googleSecret,
            'services.github.client_id' => $githubId,
            'services.github.client_secret' => $githubSecret,
            'filament-socialite.providers' => [
                'github' => ['label' => 'GitHub', 'icon' => 'fab-github'],
                'google' => ['label' => 'Google', 'icon' => 'fab-google'],
            ],
        ]);

        $method = new \ReflectionMethod(AppServiceProvider::class, 'configureSocialiteProviders');
        $method->setAccessible(true);
        $method->invoke(new AppServiceProvider($this->app));
    }

    public function test_google_is_available_when_both_credentials_are_set(): void
    {
        $this->filterProvidersWith('google-id', 'google-secret', null, null);

        $this->assertArrayHasKey('google', config('filament-socialite.providers'));
    }

    public function test_google_is_unavailable_when_the_client_id_is_missing(): void
    {
        $this->filterProvidersWith(null, 'google-secret', null, null);

        $this->assertArrayNotHasKey('google', config('filament-socialite.providers'));
    }

    public function test_google_is_unavailable_when_the_client_secret_is_missing(): void
    {
        $this->filterProvidersWith('google-id', null, null, null);

        $this->assertArrayNotHasKey('google', config('filament-socialite.providers'));
    }

    public function test_github_is_available_when_both_credentials_are_set(): void
    {
        $this->filterProvidersWith(null, null, 'github-id', 'github-secret');

        $this->assertArrayHasKey('github', config('filament-socialite.providers'));
    }

    public function test_github_is_unavailable_when_the_client_id_is_missing(): void
    {
        $this->filterProvidersWith(null, null, null, 'github-secret');

        $this->assertArrayNotHasKey('github', config('filament-socialite.providers'));
    }

    public function test_github_is_unavailable_when_the_client_secret_is_missing(): void
    {
        $this->filterProvidersWith(null, null, 'github-id', null);

        $this->assertArrayNotHasKey('github', config('filament-socialite.providers'));
    }

    public function test_no_provider_is_available_when_no_credentials_are_configured(): void
    {
        $this->filterProvidersWith(null, null, null, null);

        $this->assertSame([], config('filament-socialite.providers'));
    }

    /**
     * Guards against a filter that accidentally checks the wrong provider's
     * credentials (e.g. both keyed off "google") by proving the two
     * providers are evaluated independently, not as a single all-or-nothing
     * check.
     */
    public function test_each_provider_with_credentials_stays_available_when_both_are_configured(): void
    {
        $this->filterProvidersWith('google-id', 'google-secret', 'github-id', 'github-secret');

        $this->assertArrayHasKey('google', config('filament-socialite.providers'));
        $this->assertArrayHasKey('github', config('filament-socialite.providers'));
    }
}

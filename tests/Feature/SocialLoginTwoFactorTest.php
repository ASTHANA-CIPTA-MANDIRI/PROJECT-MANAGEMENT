<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SocialiteLoginController;
use App\Http\Livewire\TwoFactorChallenge;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use JeffGreco13\FilamentBreezy\FilamentBreezy;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Social sign-in used to call guard()->login() directly, so a user who had set
 * up two-factor authentication was logged straight in without presenting the
 * second factor — a side door around the whole 2FA policy. The login is now
 * parked until the code is verified.
 */
class SocialLoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutVite();

        config([
            'filament-socialite.enabled' => true,
            'filament-socialite.registration' => true,
        ]);

        GeneralSettings::fake(['default_role' => Role::create(['name' => 'Default role'])->id]);

        $this->secret = app(FilamentBreezy::class)->generateSecretKey();
    }

    private function mockProviderUser(string $email, string $id = '12345'): void
    {
        Socialite::shouldReceive('driver->user')->andReturn(
            (new SocialiteOAuthUser)->map([
                'id' => $id,
                'name' => 'Fajar Hero',
                'email' => $email,
            ])
        );
    }

    /**
     * An existing user with confirmed 2FA, already linked to a social account —
     * the returning-user path the callback takes.
     */
    private function linkedUserWithTwoFactor(string $email, string $providerId = '12345'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->syncRoles([Role::where('name', 'Default role')->first()]);

        SocialiteUser::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => $providerId,
        ]);

        return $user->fresh();
    }

    private function currentCode(): string
    {
        return app(FilamentBreezy::class)->getEngine()->getCurrentOtp($this->secret);
    }

    // --------------------------------------------------- the login is parked

    public function test_a_social_user_with_two_factor_is_not_logged_in_by_the_callback(): void
    {
        $user = $this->linkedUserWithTwoFactor('2fa@example.com');
        $this->mockProviderUser('2fa@example.com');

        $response = $this->get('/oauth/callback/github');

        // Social login must not authenticate before the second factor.
        $this->assertGuest();
        $response->assertRedirect(route('two-factor-challenge'));
        $this->assertSame($user->id, session(SocialiteLoginController::PENDING_SESSION_KEY)['user_id']);
    }

    public function test_a_social_user_without_two_factor_still_logs_in_directly(): void
    {
        $this->mockProviderUser('plain@example.com');

        $this->get('/oauth/callback/github');

        $this->assertAuthenticated();
    }

    public function test_the_challenge_page_renders_after_the_callback(): void
    {
        $this->linkedUserWithTwoFactor('2fa@example.com');
        $this->mockProviderUser('2fa@example.com');

        // Follows the real redirect, so the route and its blade view are
        // exercised end to end rather than only the Livewire component.
        $this->get('/oauth/callback/github')
            ->assertRedirect(route('two-factor-challenge'));

        $this->get(route('two-factor-challenge'))->assertSuccessful();
    }

    // ------------------------------------------------------- the challenge

    public function test_a_valid_code_completes_the_login(): void
    {
        $user = $this->linkedUserWithTwoFactor('2fa@example.com');
        $this->mockProviderUser('2fa@example.com');
        $this->get('/oauth/callback/github');

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', $this->currentCode())
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(SocialiteLoginController::PENDING_SESSION_KEY));
    }

    public function test_an_invalid_code_does_not_log_the_user_in(): void
    {
        $this->linkedUserWithTwoFactor('2fa@example.com');
        $this->mockProviderUser('2fa@example.com');
        $this->get('/oauth/callback/github');

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '000000')
            ->call('authenticate')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_valid_recovery_code_completes_the_login(): void
    {
        $user = $this->linkedUserWithTwoFactor('2fa@example.com');
        $user->reGenerateRecoveryCodes();
        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        $this->mockProviderUser('2fa@example.com');
        $this->get('/oauth/callback/github');

        Livewire::test(TwoFactorChallenge::class)
            ->call('toggleRecoveryCode')
            ->set('code', $recoveryCode)
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_wrong_recovery_code_does_not_log_the_user_in(): void
    {
        $user = $this->linkedUserWithTwoFactor('2fa@example.com');
        $user->reGenerateRecoveryCodes();

        $this->mockProviderUser('2fa@example.com');
        $this->get('/oauth/callback/github');

        Livewire::test(TwoFactorChallenge::class)
            ->call('toggleRecoveryCode')
            ->set('code', 'not-a-real-code')
            ->call('authenticate')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    // ------------------------------------------------------ stale / direct hits

    public function test_the_challenge_page_without_a_parked_login_redirects_to_login(): void
    {
        Livewire::test(TwoFactorChallenge::class)
            ->assertRedirect(route('filament.auth.login'));

        $this->assertGuest();
    }

    public function test_a_parked_login_cannot_be_completed_after_two_factor_is_disabled(): void
    {
        $user = $this->linkedUserWithTwoFactor('2fa@example.com');
        $this->mockProviderUser('2fa@example.com');
        $this->get('/oauth/callback/github');

        // The code is valid, but the second factor is gone: the parked entry is
        // stale and must not be honoured as a free pass.
        $code = $this->currentCode();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', $code)
            ->call('authenticate')
            ->assertRedirect(route('filament.auth.login'));

        $this->assertGuest();
    }

    public function test_a_forged_pending_session_for_another_user_still_needs_that_users_code(): void
    {
        $victim = $this->linkedUserWithTwoFactor('victim@example.com');

        // An attacker who can plant the session id still cannot pass the code.
        session([SocialiteLoginController::PENDING_SESSION_KEY => ['user_id' => $victim->id]]);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '000000')
            ->call('authenticate')
            ->assertHasErrors('code');

        $this->assertGuest();
    }
}

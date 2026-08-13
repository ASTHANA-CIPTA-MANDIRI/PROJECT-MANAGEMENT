<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Tests\TestCase;

/**
 * Drives the real filament-socialite callback with a mocked provider response,
 * so the full app-side login flow (user creation, role assignment, session)
 * is exercised exactly as it runs for a real Google/GitHub login.
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private Role $defaultRole;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // The callback redirect target renders the Filament layout, which pulls
        // in Vite assets that CI does not build; stub Vite so it is not needed.
        $this->withoutVite();

        // Social login + registration must be enabled for the callback to run.
        config([
            'filament-socialite.enabled' => true,
            'filament-socialite.registration' => true,
        ]);

        $this->defaultRole = Role::create(['name' => 'Default role']);
        GeneralSettings::fake(['default_role' => $this->defaultRole->id]);
    }

    private function mockProviderUser(string $id, string $name, string $email): void
    {
        $oauthUser = (new SocialiteOAuthUser)->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($oauthUser);
    }

    private function hitCallback(string $provider = 'github')
    {
        return $this->get("/oauth/callback/{$provider}");
    }

    // ------------------------------------------------------ new user login

    public function test_a_new_social_user_is_created_and_logged_in(): void
    {
        $this->mockProviderUser('12345', 'Fajar Hero', 'newsocial@example.com');

        $response = $this->hitCallback('github');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'newsocial@example.com']);
        $response->assertRedirect();
    }

    public function test_a_new_social_user_receives_the_default_role(): void
    {
        $this->mockProviderUser('12345', 'Fajar Hero', 'role@example.com');

        $this->hitCallback('github');

        $user = User::where('email', 'role@example.com')->first();
        $this->assertTrue($user->hasRole('Default role'));
        $this->assertTrue($user->canAccessFilament());
    }

    public function test_a_new_social_user_is_never_auto_granted_the_super_admin_role(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        GeneralSettings::fake([
            'default_role' => $superAdminRole->id,
            'super_admin_role' => null,
        ]);
        $this->mockProviderUser('12345', 'Fajar Hero', 'escalate@example.com');

        $this->hitCallback('github');

        $user = User::where('email', 'escalate@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isSuperAdmin(), 'social sign-up must never yield a Super Admin');
        $this->assertCount(0, $user->roles);
    }

    public function test_a_new_social_user_has_a_verified_email(): void
    {
        $this->mockProviderUser('12345', 'Fajar Hero', 'verified@example.com');

        $this->hitCallback('github');

        $user = User::where('email', 'verified@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_social_login_link_is_recorded(): void
    {
        $this->mockProviderUser('98765', 'Fajar Hero', 'linked@example.com');

        $this->hitCallback('google');

        $user = User::where('email', 'linked@example.com')->first();
        $this->assertDatabaseHas('socialite_users', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '98765',
        ]);
    }

    /**
     * A social user must NOT receive the "validate your account" email that is
     * meant for admin-created (type=db) users.
     */
    public function test_a_social_user_does_not_receive_the_account_validation_email(): void
    {
        $this->mockProviderUser('12345', 'Fajar Hero', 'noemail@example.com');

        $this->hitCallback('github');

        Notification::assertNothingSentTo(User::where('email', 'noemail@example.com')->first());
    }

    public function test_a_social_user_is_not_treated_as_a_db_user(): void
    {
        $this->mockProviderUser('12345', 'Fajar Hero', 'type@example.com');

        $this->hitCallback('github');

        $user = User::where('email', 'type@example.com')->first();
        $this->assertNotSame('db', $user->type);
        $this->assertNull($user->creation_token);
    }

    // ---------------------------------------------------- returning user

    public function test_a_returning_social_user_logs_in_without_duplication(): void
    {
        // First login creates the account.
        $this->mockProviderUser('555', 'Fajar Hero', 'return@example.com');
        $this->hitCallback('github');
        $this->assertSame(1, User::where('email', 'return@example.com')->count());

        // Fresh request, same provider identity logs in again.
        $this->app['auth']->logout();
        $this->mockProviderUser('555', 'Fajar Hero', 'return@example.com');
        $response = $this->hitCallback('github');

        $this->assertAuthenticated();
        $this->assertSame(1, User::where('email', 'return@example.com')->count());
        $this->assertSame(1, SocialiteUser::where('provider_id', '555')->count());
        $response->assertRedirect();
    }
}

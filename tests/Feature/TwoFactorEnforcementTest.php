<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the policy is enabled, a Super Admin without confirmed two-factor auth
 * is redirected to the profile page (to enable it) on every other panel route,
 * but is never locked out of the setup screen itself.
 */
class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(bool $withTwoFactor = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'two_factor_secret' => $withTwoFactor ? encrypt('SECRET') : null,
            'two_factor_confirmed_at' => $withTwoFactor ? now() : null,
        ]);
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    public function test_super_admin_without_2fa_is_redirected_to_profile(): void
    {
        config(['system.security.require_2fa_for_super_admin' => true]);
        $this->actingAs($this->superAdmin());

        $this->get(route('filament.pages.dashboard'))
            ->assertRedirect(route('filament.pages.my-profile'));
    }

    public function test_super_admin_without_2fa_can_still_reach_the_profile_page(): void
    {
        config(['system.security.require_2fa_for_super_admin' => true]);
        $this->actingAs($this->superAdmin());

        $this->get(route('filament.pages.my-profile'))->assertSuccessful();
    }

    public function test_super_admin_with_2fa_is_not_redirected(): void
    {
        config(['system.security.require_2fa_for_super_admin' => true]);
        $this->actingAs($this->superAdmin(withTwoFactor: true));

        $this->get(route('filament.pages.dashboard'))->assertSuccessful();
    }

    public function test_a_non_super_admin_is_not_affected(): void
    {
        config(['system.security.require_2fa_for_super_admin' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([Role::create(['name' => 'Member'])]);
        $this->actingAs($user->fresh());

        $this->get(route('filament.pages.dashboard'))->assertSuccessful();
    }

    public function test_no_enforcement_when_the_policy_is_disabled(): void
    {
        config(['system.security.require_2fa_for_super_admin' => false]);
        $this->actingAs($this->superAdmin());

        $this->get(route('filament.pages.dashboard'))->assertSuccessful();
    }
}

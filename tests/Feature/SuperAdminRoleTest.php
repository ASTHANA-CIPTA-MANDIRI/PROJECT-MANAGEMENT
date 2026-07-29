<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which role counts as "Super Admin" is configured in Settings; isSuperAdmin()
 * follows that choice, falling back to a role named "Super Admin" when unset.
 */
class SuperAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_super_admin_uses_the_configured_role(): void
    {
        $configuredRole = Role::create(['name' => 'Owner']);
        $namedRole = Role::create(['name' => 'Super Admin']); // NOT the configured one
        GeneralSettings::fake(['super_admin_role' => (string) $configuredRole->id]);

        $owner = User::factory()->create();
        $owner->syncRoles([$configuredRole]);

        $named = User::factory()->create();
        $named->syncRoles([$namedRole]);

        $this->assertTrue($owner->fresh()->isSuperAdmin());
        $this->assertFalse(
            $named->fresh()->isSuperAdmin(),
            'the literal name "Super Admin" must not override the configured role'
        );
    }

    public function test_is_super_admin_falls_back_to_the_named_role_when_unset(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);

        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        $this->assertTrue($user->fresh()->isSuperAdmin());
    }
}

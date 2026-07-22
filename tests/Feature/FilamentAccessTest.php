<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user without any assigned role must not be allowed into the panel.
     */
    public function test_user_without_role_cannot_access_filament(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(
            $user->canAccessFilament(),
            'A user with no roles should be denied access to the Filament panel.'
        );
    }

    /**
     * A user with at least one assigned role may access the panel.
     */
    public function test_user_with_role_can_access_filament(): void
    {
        $role = Role::create(['name' => 'Default role']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $user->refresh();

        $this->assertTrue(
            $user->canAccessFilament(),
            'A user with an assigned role should be allowed into the Filament panel.'
        );
    }

    /**
     * Removing every role from a user revokes panel access again.
     */
    public function test_removing_roles_revokes_filament_access(): void
    {
        $role = Role::create(['name' => 'Default role']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $user->refresh();
        $this->assertTrue($user->canAccessFilament());

        $user->syncRoles([]);
        $user->refresh();

        $this->assertFalse(
            $user->canAccessFilament(),
            'A user should lose panel access once all roles are removed.'
        );
    }
}

<?php

namespace Tests\Feature\Policies;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * L-4: UserPolicy::update()/delete() must refuse to touch a Super Admin
 * account unless the actor is a Super Admin too - mirroring RolePolicy::
 * update()'s guard on the Super Admin role. Without this, the generic
 * "Update user" / "Delete user" permissions were a back door: any field
 * (name, email, ...) on a Super Admin account could be edited, and any
 * non-last Super Admin could be deleted, by a plain user manager.
 */
class UserPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSettings::fake(['super_admin_role' => null]);

        Permission::firstOrCreate(['name' => 'Update user']);
        Permission::firstOrCreate(['name' => 'Delete user']);

        $this->superAdminRole = Role::create(['name' => 'Super Admin']);
        $this->superAdminRole->syncPermissions(['Update user', 'Delete user']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->superAdminRole]);
        $this->flushPermissionCache();

        return $user->fresh();
    }

    // -------------------------------------------------------------- update

    public function test_a_user_manager_cannot_update_a_super_admins_email_or_name(): void
    {
        $admin = $this->superAdmin();
        $manager = $this->userWithPermissions(['Update user']);

        $this->assertFalse($manager->can('update', $admin));
    }

    public function test_a_user_manager_can_still_update_a_regular_user(): void
    {
        $target = User::factory()->create();
        $manager = $this->userWithPermissions(['Update user']);

        $this->assertTrue($manager->can('update', $target));
    }

    public function test_a_super_admin_can_update_another_super_admin(): void
    {
        $admin = $this->superAdmin();
        $editor = $this->superAdmin();

        $this->assertTrue($editor->can('update', $admin));
    }

    public function test_a_user_without_the_permission_cannot_update_anyone(): void
    {
        $target = User::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->assertFalse($user->can('update', $target));
    }

    // -------------------------------------------------------------- delete

    public function test_a_user_manager_cannot_delete_a_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second one, so the "last admin" guard isn't what's under test
        $manager = $this->userWithPermissions(['Delete user']);

        $this->assertFalse($manager->can('delete', $admin));
    }

    public function test_a_user_manager_can_still_delete_a_regular_user(): void
    {
        $target = User::factory()->create();
        $manager = $this->userWithPermissions(['Delete user']);

        $this->assertTrue($manager->can('delete', $target));
    }

    public function test_a_super_admin_can_delete_another_super_admin(): void
    {
        $admin = $this->superAdmin();
        $deleter = $this->superAdmin();

        $this->assertTrue($deleter->can('delete', $admin));
    }

    public function test_a_user_without_the_permission_cannot_delete_anyone(): void
    {
        $target = User::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->assertFalse($user->can('delete', $target));
    }
}

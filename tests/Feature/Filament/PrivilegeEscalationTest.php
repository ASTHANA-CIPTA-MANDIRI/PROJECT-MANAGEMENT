<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Update user" and "Update role" must not be a back door to Super Admin: a
 * user may neither hand out the Super Admin role nor grant a role permissions
 * they don't hold themselves.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    /** Everything a user or role manager needs to reach the pages under test. */
    private const MANAGEMENT_PERMISSIONS = [
        'List users', 'View user', 'Update user',
        'List roles', 'View role', 'Update role',
    ];

    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSettings::fake(['super_admin_role' => null]);

        foreach (self::MANAGEMENT_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // This app has no blanket "super admin can do anything" gate: the role
        // carries real permissions, exactly as the seeder builds it.
        $this->superAdminRole = Role::create(['name' => 'Super Admin']);
        $this->superAdminRole->syncPermissions(self::MANAGEMENT_PERMISSIONS);
    }

    /**
     * A user holding exactly the given permissions, through a role of its own.
     */
    private function userWithPermissions(array $permissions, string $roleName): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->superAdminRole]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ------------------------------------------------ granting the admin role

    public function test_a_user_manager_cannot_grant_themselves_the_super_admin_role(): void
    {
        $this->superAdmin(); // so the target is not the last Super Admin story
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $manager->id])
            ->fillForm(['roles' => [$this->superAdminRole->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($manager->fresh()->isSuperAdmin(), 'the role must not have been granted');
    }

    public function test_a_user_manager_cannot_grant_the_super_admin_role_to_someone_else(): void
    {
        $this->superAdmin();
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $confederate = User::factory()->create();
        $confederate->syncRoles([Role::where('name', 'Manager')->first()]);
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $confederate->id])
            ->fillForm(['roles' => [$this->superAdminRole->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($confederate->fresh()->isSuperAdmin());
    }

    public function test_a_super_admin_can_grant_the_super_admin_role(): void
    {
        $admin = $this->superAdmin();
        $promoted = $this->userWithPermissions(['List users'], 'Viewer');
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $promoted->id])
            ->fillForm(['roles' => [$this->superAdminRole->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($promoted->fresh()->isSuperAdmin());
    }

    /**
     * L-4: UserPolicy::update() used to only check the generic "Update user"
     * permission, so a plain user manager could reach this page and rename
     * (or otherwise edit) a Super Admin - even leaving the role field alone,
     * i.e. without touching the privilege-escalation guard on that field at
     * all. The policy must now refuse to open the page in the first place.
     */
    public function test_a_user_manager_cannot_open_a_super_admin_for_editing(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second one, so the guard against the last admin isn't what's under test
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->assertForbidden();

        $this->assertSame($admin->email, $admin->fresh()->email, 'the Super Admin account must be untouched');
    }

    public function test_a_super_admin_can_still_edit_another_super_admin(): void
    {
        $admin = $this->superAdmin();
        $editor = $this->superAdmin();
        $this->actingAs($editor);

        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->fillForm(['name' => 'Renamed', 'roles' => [$this->superAdminRole->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $admin->fresh()->name);
    }

    // ------------------------------------- granting a role stronger than yours

    public function test_a_user_manager_cannot_grant_a_role_holding_permissions_they_lack(): void
    {
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $strong = Role::create(['name' => 'Destroyer']);
        $strong->syncPermissions([Permission::firstOrCreate(['name' => 'Delete user'])]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $manager->id])
            ->fillForm(['roles' => [$manager->roles->first()->id, $strong->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($manager->fresh()->can('Delete user'));
    }

    public function test_a_user_manager_can_grant_a_role_within_their_own_permissions(): void
    {
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $weaker = Role::create(['name' => 'Viewer']);
        $weaker->syncPermissions(['List users']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target = User::factory()->create();
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['roles' => [$weaker->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($target->fresh()->hasRole('Viewer'));
    }

    public function test_editing_a_user_who_already_holds_a_strong_role_still_works(): void
    {
        $manager = $this->userWithPermissions(['List users', 'View user', 'Update user'], 'Manager');
        $strong = Role::create(['name' => 'Destroyer']);
        $strong->syncPermissions([Permission::firstOrCreate(['name' => 'Delete user'])]);
        $target = User::factory()->create();
        $target->syncRoles([$strong]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['name' => 'Renamed', 'roles' => [$strong->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $target->fresh()->name);
    }

    // ------------------------------------------------- granting permissions

    public function test_a_role_manager_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $manager = $this->userWithPermissions(['List roles', 'View role', 'Update role'], 'Manager');
        $deleteUser = Permission::firstOrCreate(['name' => 'Delete user']);
        $target = Role::where('name', 'Manager')->first();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(EditRole::class, ['record' => $target->getRouteKey()])
            ->fillForm(['permissions' => [$deleteUser->id]])
            ->call('save')
            ->assertHasFormErrors(['permissions']);

        $this->assertFalse($manager->fresh()->can('Delete user'));
    }

    public function test_a_role_manager_can_keep_permissions_the_role_already_has(): void
    {
        $manager = $this->userWithPermissions(['List roles', 'View role', 'Update role'], 'Manager');
        $deleteUser = Permission::firstOrCreate(['name' => 'Delete user']);
        $other = Role::create(['name' => 'Destroyer']);
        $other->syncPermissions([$deleteUser]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(EditRole::class, ['record' => $other->getRouteKey()])
            ->fillForm(['name' => 'Destroyer renamed', 'permissions' => [$deleteUser->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Destroyer renamed', $other->fresh()->name);
    }

    public function test_a_super_admin_can_grant_a_permission_they_do_not_hold_themselves(): void
    {
        $admin = $this->superAdmin();
        $jira = Permission::firstOrCreate(['name' => 'Import from Jira']); // not on the Super Admin role here
        $target = Role::create(['name' => 'Editor']);
        $target->syncPermissions(['List roles']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('Import from Jira'));

        Livewire::test(EditRole::class, ['record' => $target->getRouteKey()])
            ->fillForm(['permissions' => [$jira->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($target->fresh()->hasPermissionTo('Import from Jira'));
    }

    // ------------------------------------------- editing the admin role itself

    public function test_a_role_manager_cannot_open_the_super_admin_role_for_editing(): void
    {
        $manager = $this->userWithPermissions(['List roles', 'View role', 'Update role'], 'Manager');
        $this->actingAs($manager);

        Livewire::test(EditRole::class, ['record' => $this->superAdminRole->getRouteKey()])
            ->assertForbidden();
    }

    public function test_a_super_admin_can_edit_the_super_admin_role(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(EditRole::class, ['record' => $this->superAdminRole->getRouteKey()])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- deleting a Super Admin (L-4)

    /**
     * DeleteBulkAction is auto-wired (AppServiceProvider) to filter each
     * selected record through the model's policy, so this also proves the
     * new UserPolicy::delete() guard reaches the bulk-delete path, not just
     * the single-record one.
     */
    public function test_a_user_manager_cannot_bulk_delete_a_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second one, so the "last admin" guard isn't what's under test
        $manager = $this->userWithPermissions(['List users', 'View user', 'Delete user'], 'Manager');
        $this->actingAs($manager);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$admin]);

        $this->assertNotNull(User::find($admin->id), 'a Super Admin must survive a bulk delete by a non-Super-Admin');
    }

    public function test_a_super_admin_can_bulk_delete_another_super_admin(): void
    {
        Permission::firstOrCreate(['name' => 'Delete user']);
        $this->superAdminRole->givePermissionTo('Delete user');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = $this->superAdmin();
        $deleter = $this->superAdmin();
        $this->actingAs($deleter);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$admin]);

        $this->assertNull(User::find($admin->id));
    }

    public function test_a_user_manager_can_still_bulk_delete_a_regular_user(): void
    {
        $target = User::factory()->create();
        $manager = $this->userWithPermissions(['List users', 'View user', 'Delete user'], 'Manager');
        $this->actingAs($manager);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$target]);

        $this->assertNull(User::find($target->id));
    }
}

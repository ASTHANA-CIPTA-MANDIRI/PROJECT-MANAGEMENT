<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Role name field must validate against the roles table (unique on
 * name+guard_name), not the permissions table: a duplicate role name should
 * fail validation cleanly instead of reaching Spatie's RoleAlreadyExists and
 * a 500, and a role may legitimately share its name with a permission.
 */
class RoleNameValidationTest extends TestCase
{
    use RefreshDatabase;

    private function roleManager(): User
    {
        foreach (['List roles', 'View role', 'Create role', 'Update role'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Role Manager']);
        $role->syncPermissions(['List roles', 'View role', 'Create role', 'Update role']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSettings::fake(['super_admin_role' => null]);
    }

    public function test_creating_a_role_with_a_duplicate_name_fails_validation_instead_of_500(): void
    {
        Role::create(['name' => 'Editor']);
        $this->actingAs($this->roleManager());

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Editor', 'permissions' => []])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(1, Role::where('name', 'Editor')->count());
    }

    public function test_creating_a_role_with_the_same_name_as_a_permission_succeeds(): void
    {
        Permission::firstOrCreate(['name' => 'Editor']);
        $manager = $this->roleManager();
        $ownPermission = Permission::where('name', 'Update role')->first();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Editor', 'permissions' => [$ownPermission->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Role::where('name', 'Editor')->exists());
    }

    public function test_saving_a_role_without_changing_its_name_still_works(): void
    {
        $role = Role::create(['name' => 'Editor']);
        $manager = $this->roleManager();
        $role->syncPermissions(['Update role']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'Editor', 'permissions' => [Permission::where('name', 'Update role')->first()->id]])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_renaming_a_role_to_another_roles_name_fails_validation(): void
    {
        $role = Role::create(['name' => 'Editor']);
        Role::create(['name' => 'Viewer']);
        $this->actingAs($this->roleManager());

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'Viewer', 'permissions' => []])
            ->call('save')
            ->assertHasFormErrors(['name']);

        $this->assertSame('Editor', $role->fresh()->name);
    }
}

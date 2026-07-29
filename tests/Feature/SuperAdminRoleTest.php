<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
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

    // ------------------------------------------------ guardrails (no lock-out)

    public function test_the_last_super_admin_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles([Role::create(['name' => 'Super Admin'])]);

        $this->assertFalse((bool) $admin->fresh()->delete());
        $this->assertNotNull(User::find($admin->id), 'the last Super Admin must survive deletion');
    }

    public function test_a_super_admin_can_be_deleted_when_another_remains(): void
    {
        $role = Role::create(['name' => 'Super Admin']);
        $a = User::factory()->create();
        $a->syncRoles([$role]);
        $b = User::factory()->create();
        $b->syncRoles([$role]);

        $this->assertTrue((bool) $a->fresh()->delete());
        $this->assertNull(User::find($a->id));
    }

    public function test_cannot_remove_the_super_admin_role_from_the_last_super_admin(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $otherRole = Role::create(['name' => 'Viewer']);

        $lastAdmin = User::factory()->create();
        $lastAdmin->syncRoles([$superAdminRole]);

        // An editor who can manage users but is not the Super Admin.
        foreach (['List users', 'View user', 'Update user'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $editorRole = Role::create(['name' => 'Manager']);
        $editorRole->syncPermissions(['List users', 'View user', 'Update user']);
        $editor = User::factory()->create();
        $editor->syncRoles([$editorRole]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($editor->fresh());

        Livewire::test(EditUser::class, ['record' => $lastAdmin->id])
            ->fillForm(['roles' => [$otherRole->id]]) // drop the Super Admin role
            ->call('save')
            ->assertHasFormErrors(['roles']);
    }
}

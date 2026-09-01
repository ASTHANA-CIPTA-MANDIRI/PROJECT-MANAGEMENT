<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The general settings page hands out privileges in two ways: the default role
 * goes to every new registrant, and the Super Admin role setting decides who
 * counts as the main administrator. Neither may be used to reach privileges the
 * actor does not already hold.
 */
class SettingsEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions, string $roleName = 'Settings manager'): User
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
        // This app has no blanket "super admin can do anything" gate, so the
        // role must really carry the permissions the page requires.
        Permission::firstOrCreate(['name' => 'Manage general settings']);
        Permission::firstOrCreate(['name' => ManageGeneralSettings::SUPER_ADMIN_PERMISSION]);

        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->syncPermissions(Permission::all()->pluck('name')->toArray());

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Settings are cached on the container, so re-read them after a save. */
    private function settings(): GeneralSettings
    {
        app()->forgetInstance(GeneralSettings::class);

        return app(GeneralSettings::class);
    }

    // ------------------------------------------------------- default role

    public function test_a_settings_manager_cannot_choose_a_default_role_stronger_than_their_own(): void
    {
        $manager = $this->actor(['Manage general settings']);
        $strong = Role::create(['name' => 'All power']);
        $strong->syncPermissions([Permission::firstOrCreate(['name' => 'Delete user'])]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['default_role' => (string) $strong->id])
            ->call('save')
            ->assertHasErrors(['data.default_role']);

        $this->assertNull($this->settings()->default_role, 'the strong role must not have been stored');
    }

    public function test_a_settings_manager_can_choose_a_default_role_within_their_own_permissions(): void
    {
        $manager = $this->actor(['Manage general settings']);
        $weaker = Role::create(['name' => 'Guest']);
        $weaker->syncPermissions(['Manage general settings']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['default_role' => (string) $weaker->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame((string) $weaker->id, $this->settings()->default_role);
    }

    public function test_a_legacy_strong_default_role_does_not_block_saving_other_settings(): void
    {
        $strong = Role::create(['name' => 'Legacy default']);
        $strong->syncPermissions([Permission::firstOrCreate(['name' => 'Delete user'])]);

        $settings = app(GeneralSettings::class);
        $settings->default_role = (string) $strong->id;
        $settings->save();

        $manager = $this->actor(['Manage general settings']);
        $this->actingAs($manager);

        // The value is untouched, so the guard must not fire — otherwise a
        // legacy instance could never save its settings page again.
        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['site_name' => 'Renamed'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $this->settings()->site_name);
    }

    public function test_a_super_admin_may_choose_any_default_role(): void
    {
        $admin = $this->superAdmin();
        $strong = Role::create(['name' => 'All power']);
        $strong->syncPermissions([Permission::firstOrCreate(['name' => 'Import from Jira'])]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['default_role' => (string) $strong->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame((string) $strong->id, $this->settings()->default_role);
    }

    // --------------------------------------------------- super admin role

    public function test_the_super_admin_role_setting_cannot_be_pointed_at_your_own_role(): void
    {
        $manager = $this->actor([
            'Manage general settings',
            ManageGeneralSettings::SUPER_ADMIN_PERMISSION,
        ]);
        $ownRole = $manager->roles->first();
        $this->actingAs($manager);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['super_admin_role' => (string) $ownRole->id])
            ->call('save')
            ->assertHasErrors(['data.super_admin_role']);

        $this->assertNull($this->settings()->super_admin_role);
        $this->assertFalse($manager->fresh()->isSuperAdmin());
    }

    public function test_the_super_admin_role_setting_may_point_at_a_role_you_do_not_hold(): void
    {
        $manager = $this->actor([
            'Manage general settings',
            ManageGeneralSettings::SUPER_ADMIN_PERMISSION,
        ]);
        $other = Role::create(['name' => 'Owners']);
        $this->actingAs($manager);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['super_admin_role' => (string) $other->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame((string) $other->id, $this->settings()->super_admin_role);
    }

    public function test_a_settings_manager_without_the_permission_cannot_change_the_super_admin_role(): void
    {
        $manager = $this->actor(['Manage general settings']);
        $other = Role::create(['name' => 'Owners']);
        $this->actingAs($manager);

        // The select is hidden for this user; a crafted payload is overwritten
        // with the stored value rather than rejected.
        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['super_admin_role' => (string) $other->id])
            ->call('save');

        $this->assertNull($this->settings()->super_admin_role);
    }
}

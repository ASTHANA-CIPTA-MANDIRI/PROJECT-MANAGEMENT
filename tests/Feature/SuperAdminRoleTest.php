<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_dangling_super_admin_role_setting_falls_back_to_the_named_role(): void
    {
        $named = Role::create(['name' => 'Super Admin']);
        GeneralSettings::fake(['super_admin_role' => '999999']); // points to a role that doesn't exist

        $user = User::factory()->create();
        $user->syncRoles([$named]);

        // The dangling id is ignored, so it falls back to the named role.
        $this->assertNull(User::superAdminRoleId());
        $this->assertTrue($user->fresh()->isSuperAdmin());
    }

    // --------------------------------------------------- N+1 / caching

    public function test_super_admin_role_id_is_resolved_only_once_per_request(): void
    {
        $role = Role::create(['name' => 'Owner']);
        GeneralSettings::fake(['super_admin_role' => (string) $role->id]);

        DB::enableQueryLog();
        $first = User::superAdminRoleId();
        $queriesAfterFirstCall = count(DB::getQueryLog());
        $second = User::superAdminRoleId();
        $queriesAfterSecondCall = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame((string) $role->id, $first);
        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $queriesAfterFirstCall, 'the first call should still hit the database');
        $this->assertSame(
            $queriesAfterFirstCall,
            $queriesAfterSecondCall,
            'a second call in the same request must be served from cache, not re-query'
        );
    }

    public function test_is_super_admin_uses_the_eager_loaded_roles_relation_instead_of_querying(): void
    {
        $role = Role::create(['name' => 'Owner']);
        GeneralSettings::fake(['super_admin_role' => (string) $role->id]);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        $loaded = User::with('roles')->whereKey($user->id)->first();

        // Warm the super-admin-role-id cache, as the first row of a users
        // table listing would, so only the roles-relation query is measured.
        User::superAdminRoleId();

        DB::enableQueryLog();
        $result = $loaded->isSuperAdmin();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($result);
        $this->assertSame(0, $queryCount, 'isSuperAdmin() must reuse the eager-loaded roles relation, not query');
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

    public function test_the_configured_super_admin_role_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'Owner']);
        GeneralSettings::fake(['super_admin_role' => (string) $role->id]);

        $this->assertFalse((bool) $role->fresh()->delete());
        $this->assertNotNull(Role::find($role->id), 'the Super Admin role must not be deletable');
    }

    public function test_the_named_super_admin_role_cannot_be_deleted_when_unconfigured(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);
        $role = Role::create(['name' => 'Super Admin']);

        $this->assertFalse((bool) $role->fresh()->delete());
        $this->assertNotNull(Role::find($role->id));
    }

    public function test_a_normal_role_can_be_deleted(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);
        $role = Role::create(['name' => 'Editor']);

        $this->assertTrue((bool) $role->fresh()->delete());
        $this->assertNull(Role::find($role->id));
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

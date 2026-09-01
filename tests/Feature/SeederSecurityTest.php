<?php

namespace Tests\Feature;

use App\Listeners\AssignDefaultRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultUserSeeder;
use Database\Seeders\EmployeeRoleSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function seedRolesAndAdmin(): void
    {
        $this->seed(DefaultUserSeeder::class);
        $this->seed(PermissionsSeeder::class);
    }

    public function test_employee_role_is_a_coherent_worker_role(): void
    {
        $this->seed(PermissionsSeeder::class);
        $this->seed(EmployeeRoleSeeder::class);

        $role = Role::findByName('Employee');

        // Can view whatever it can list — no "List without View" 403 footgun.
        $this->assertTrue($role->hasPermissionTo('List tickets'));
        $this->assertTrue($role->hasPermissionTo('View ticket'));
        $this->assertTrue($role->hasPermissionTo('View project'));

        // Not an administrator: no destructive or user/role management.
        $this->assertFalse($role->hasPermissionTo('Delete ticket'));
        $this->assertFalse($role->hasPermissionTo('Update user'));
        $this->assertFalse($role->hasPermissionTo('Delete role'));
    }

    public function test_seeding_creates_a_super_admin_role_with_all_permissions(): void
    {
        $this->seedRolesAndAdmin();

        $superAdmin = Role::where('name', 'Super Admin')->first();

        $this->assertNotNull($superAdmin, 'Super Admin role should exist');
        $this->assertSame(Permission::count(), $superAdmin->permissions()->count());
        $this->assertTrue(User::first()->hasRole('Super Admin'));
    }

    public function test_seeding_does_not_set_a_privileged_default_role(): void
    {
        $this->seedRolesAndAdmin();

        // No default role → new registrants stay pending until an admin assigns
        // them one, so self-registration can never grant admin access.
        $this->assertNull(app(GeneralSettings::class)->default_role);
    }

    public function test_registration_grants_no_role_under_the_seeded_defaults(): void
    {
        $this->seedRolesAndAdmin();

        $newUser = User::factory()->create();
        (new AssignDefaultRole)->handle(new Registered($newUser));

        $this->assertCount(0, $newUser->fresh()->roles);
        $this->assertFalse($newUser->fresh()->canAccessFilament(), 'pending user must not reach the panel');
    }

    public function test_default_user_seeder_is_idempotent(): void
    {
        $this->seed(DefaultUserSeeder::class);
        $this->seed(DefaultUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
    }

    public function test_seeded_admin_password_is_not_the_weak_default(): void
    {
        $this->seed(DefaultUserSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();

        $this->assertFalse(Hash::check('123', $admin->password), 'admin must not keep the old weak password');
    }

    public function test_the_full_seed_runs_and_gives_each_seeded_account_its_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();
        $worker = User::where('email', 'user@example.com')->first();

        $this->assertTrue($admin->hasRole('Super Admin'));
        $this->assertTrue($worker->hasRole('Employee'));

        // The demo worker must never outrank the admin, nor take the
        // User::first() slot PermissionsSeeder uses to bootstrap Super Admin.
        $this->assertFalse($worker->hasRole('Super Admin'));
        $this->assertTrue($admin->is(User::first()));
    }

    public function test_the_seeded_worker_falls_back_to_a_generated_password(): void
    {
        // This is about the seeder's own fallback, so ignore whatever the
        // developer happens to have pinned in their .env.
        $pinned = getenv('SEED_USER_PASSWORD');
        putenv('SEED_USER_PASSWORD');
        unset($_ENV['SEED_USER_PASSWORD'], $_SERVER['SEED_USER_PASSWORD']);

        try {
            $this->seed(DatabaseSeeder::class);

            $worker = User::where('email', 'user@example.com')->first();

            $this->assertFalse(Hash::check('password123', $worker->password), 'no hard-coded default may ship');
            $this->assertFalse(Hash::check('123', $worker->password));
            $this->assertFalse(Hash::check('password', $worker->password));
        } finally {
            if ($pinned !== false) {
                putenv("SEED_USER_PASSWORD={$pinned}");
                $_ENV['SEED_USER_PASSWORD'] = $pinned;
                $_SERVER['SEED_USER_PASSWORD'] = $pinned;
            }
        }
    }

    public function test_the_demo_worker_is_not_seeded_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // Called directly: db:seed asks for confirmation in production.
        app(DefaultUserSeeder::class)->run();

        $this->assertNull(User::where('email', 'user@example.com')->first());
        $this->assertNotNull(User::where('email', 'admin@example.com')->first());
    }

    public function test_re_seeding_keeps_a_role_an_admin_changed_by_hand(): void
    {
        $this->seed(DatabaseSeeder::class);

        $worker = User::where('email', 'user@example.com')->first();
        $worker->syncRoles([]);

        $this->seed(DefaultUserSeeder::class);

        $this->assertCount(0, $worker->fresh()->roles, 'a re-seed must not re-grant a revoked role');
        $this->assertSame(1, User::where('email', 'user@example.com')->count());
    }
}

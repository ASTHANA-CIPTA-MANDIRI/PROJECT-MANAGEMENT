<?php

namespace Tests\Feature;

use App\Listeners\AssignDefaultRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\DefaultUserSeeder;
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
}

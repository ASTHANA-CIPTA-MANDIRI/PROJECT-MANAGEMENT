<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultUserSeeder extends Seeder
{
    /**
     * Seed the initial administrator, plus a plain worker account outside
     * production.
     *
     * Idempotent: keyed on the emails it actually creates (the previous version
     * checked a different address and, since the users.email unique constraint
     * was dropped, produced duplicate admins on re-seed).
     *
     * Passwords come from SEED_ADMIN_PASSWORD / SEED_USER_PASSWORD; when unset a
     * strong random one is generated and printed once, so no weak hard-coded
     * credential ('123', 'password123') ever ships.
     *
     * The administrator is created first on purpose: PermissionsSeeder falls
     * back to User::first() to bootstrap the Super Admin role on existing
     * installs, so the demo worker must never be able to take that slot.
     */
    public function run(): void
    {
        $admin = $this->seedUser(
            env('SEED_ADMIN_EMAIL', 'admin@example.com'),
            'Administrator',
            'SEED_ADMIN_PASSWORD',
            'Super Admin',
        );

        if ($admin->wasRecentlyCreated) {
            $admin->creation_token = null;
            $admin->save();
        }

        // A ready-to-use non-admin login for local work and demos. Never seeded
        // in production: real accounts are created in the panel, where an admin
        // picks the role/package deliberately.
        if (! app()->environment('production')) {
            $this->seedUser(
                env('SEED_USER_EMAIL', 'user@example.com'),
                'User Biasa',
                'SEED_USER_PASSWORD',
                'Employee',
            );
        }
    }

    /**
     * Create the account if it is missing and give it its starting role.
     *
     * The role is only applied to a freshly created user, so re-seeding never
     * overwrites a role an administrator has since changed by hand. It is also
     * skipped when the role does not exist yet, which happens when this seeder
     * is run on its own instead of through DatabaseSeeder.
     */
    private function seedUser(string $email, string $name, string $passwordKey, string $role): User
    {
        $password = env($passwordKey);
        $generated = false;

        if (! $password) {
            $password = Str::random(20);
            $generated = true;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->wasRecentlyCreated) {
            return $user;
        }

        if (Role::where('name', $role)->exists()) {
            $user->syncRoles([$role]);
        }

        if ($generated && $this->command) {
            $this->command->warn("Seeded [{$email}] with a generated password: {$password}");
            $this->command->warn('Save it now and change it after the first login.');
        }

        return $user;
    }
}

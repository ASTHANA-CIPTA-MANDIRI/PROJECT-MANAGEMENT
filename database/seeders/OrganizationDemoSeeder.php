<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Phase 5.5 (Step 2.5) — development/manual-test fixtures for Organization
 * RBAC (Phase 4/5/5.1). NOT called by DatabaseSeeder and never runs
 * automatically: invoke explicitly with
 * `php artisan db:seed --class=OrganizationDemoSeeder`.
 *
 * Idempotent by design, mirroring DefaultUserSeeder's conventions:
 * - Users are firstOrCreate()'d by email, so re-running never duplicates them.
 * - Organizations are firstOrCreate()'d by name (organizations.name already
 *   has a unique index — see 2026_09_02_000001_create_organizations_table.php
 *   — so this can never produce a duplicate at the database level either).
 * - Memberships use syncWithoutDetaching() keyed on (organization, user),
 *   which — unlike DefaultUserSeeder's "never touch a role an admin already
 *   changed by hand" stance for real accounts — deliberately DOES update the
 *   role back to the documented matrix below on every re-run. This is
 *   throwaway RBAC test data: the point of re-seeding is to reset it to a
 *   known state after manually testing role changes, not to preserve
 *   whatever got changed while poking at the UI.
 *
 * Refuses to run in production outright (not just per-user like
 * DefaultUserSeeder's demo account): every user and organization this
 * creates is disposable test data, so there is no legitimate reason for any
 * part of it to exist outside development/local/testing.
 */
class OrganizationDemoSeeder extends Seeder
{
    /**
     * DEVELOPMENT / LOCAL ONLY. Every account this seeder creates shares
     * this password — never used for the real admin/user accounts
     * DefaultUserSeeder creates, and never valid in production since this
     * class refuses to run there at all.
     */
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error(
                'OrganizationDemoSeeder refuses to run in production - it only ever creates disposable RBAC test data.'
            );

            return;
        }

        $owner = $this->user('owner@example.test', 'Test Owner');
        $admin = $this->user('admin@example.test', 'Test Admin');
        $member = $this->user('member@example.test', 'Test Member');
        $multi = $this->user('multi@example.test', 'Test Multi Organization User');
        // Deliberately given no membership below - the "0 organizations" /
        // "no automatic organization" manual test case.
        $this->user('noorg@example.test', 'Test No Organization User');

        $alpha = Organization::firstOrCreate(['name' => 'Organization Alpha']);
        $beta = Organization::firstOrCreate(['name' => 'Organization Beta']);
        $gamma = Organization::firstOrCreate(['name' => 'Organization Gamma']);
        // A fourth organization with a single member (its owner) - the
        // "newly created / empty organization" manual test case.
        $delta = Organization::firstOrCreate(['name' => 'Organization Delta']);

        $this->membership($alpha, $owner, 'owner');
        $this->membership($alpha, $admin, 'admin');
        $this->membership($alpha, $member, 'member');
        $this->membership($alpha, $multi, 'member');

        $this->membership($beta, $multi, 'owner');
        $this->membership($beta, $admin, 'member');

        $this->membership($gamma, $multi, 'owner');
        $this->membership($gamma, $member, 'member');

        $this->membership($delta, $owner, 'owner');

        $this->command->info(
            'Organization RBAC demo data ready. Every seeded user shares the password "'.self::PASSWORD.'" (development/local only).'
        );
    }

    /**
     * Also granted the existing "Employee" role (when it exists - i.e. when
     * EmployeeRoleSeeder has already run) purely so these accounts can open
     * the Filament panel at all: User::canAccessFilament() requires holding
     * at least one role, entirely independent of Organization membership.
     * Skipped gracefully otherwise, the same defensive pattern
     * DefaultUserSeeder already uses for its own accounts.
     */
    private function user(string $email, string $name): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        if ($role = Role::where('name', 'Employee')->first()) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function membership(Organization $organization, User $user, string $role): void
    {
        $organization->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }
}

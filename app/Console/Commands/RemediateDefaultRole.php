<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

/**
 * Fixes the data-only privilege-escalation risk that `security:audit-escalation`
 * reports but never changes: a database seeded before the seeder security fix
 * (commit 8daea2a) can still have `default_role` pointing at a legacy
 * all-permission "Default role" handed to every self-registrant.
 *
 * This only clears the *setting*, so new registrants stop receiving the role.
 * Users who already hold it are listed, not touched — deciding what they
 * should be downgraded to needs a human, not a heuristic.
 */
class RemediateDefaultRole extends Command
{
    protected $signature = 'security:remediate-default-role
        {--dry-run : Report what would change without changing anything}';

    protected $description = 'Clear a default role that could hand every new registrant administrative access';

    public function handle(): int
    {
        $settings = app(GeneralSettings::class);

        if (blank($settings->default_role)) {
            $this->info('No default role is configured. Nothing to remediate.');

            return self::SUCCESS;
        }

        $role = Role::find($settings->default_role);

        if (! $role) {
            $this->info('The default role setting points at a role that no longer exists; nothing is assigned. Nothing to remediate.');

            return self::SUCCESS;
        }

        $totalPermissions = Permission::count();
        $held = $role->permissions()->count();

        if (! $this->isEscalating($role, $held, $totalPermissions)) {
            $this->info("Default role \"{$role->name}\" ({$held}/{$totalPermissions} permissions) does not look escalating. Nothing to remediate.");

            return self::SUCCESS;
        }

        $this->warn("Default role \"{$role->name}\" is escalating ({$held}/{$totalPermissions} permissions held).");

        $holders = User::whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))->get();

        if ($holders->isEmpty()) {
            $this->line('  no users currently hold this role.');
        } else {
            $this->line('  '.$holders->count().' user(s) already hold it and should be reviewed manually:');
            foreach ($holders as $holder) {
                $this->line("    - {$holder->email}");
            }
        }

        if ($this->option('dry-run')) {
            $this->line('');
            $this->info('[dry-run] Would clear the default role setting. No users were modified.');

            return self::SUCCESS;
        }

        $settings->default_role = null;
        $settings->save();

        $this->line('');
        $this->info('Cleared the default role setting; new registrants now stay pending until an admin assigns a role.');

        if ($holders->isNotEmpty()) {
            $this->warn('Existing holders listed above still have the role — review and downgrade them from the Roles screen.');
        }

        return self::SUCCESS;
    }

    /**
     * Mirrors the risk criteria `security:audit-escalation` uses: the Super
     * Admin role itself, anything that can manage roles/users/settings, or a
     * role that happens to hold every permission in the system.
     */
    private function isEscalating(Role $role, int $held, int $totalPermissions): bool
    {
        if ($role->isSuperAdminRole()) {
            return true;
        }

        $canManageAccess = $role->permissions()
            ->whereIn('name', [
                'Update role', 'Create role', 'Update user', 'Create user',
                'Manage super admin settings', 'Manage general settings',
            ])
            ->exists();

        return $canManageAccess || ($totalPermissions > 0 && $held === $totalPermissions);
    }
}

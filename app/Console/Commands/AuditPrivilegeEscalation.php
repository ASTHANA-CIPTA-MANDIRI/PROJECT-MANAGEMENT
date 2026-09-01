<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

/**
 * Reports privilege-escalation risks that live in *data*, not in code.
 *
 * The application guards now refuse to create these states, but a database
 * seeded before those guards existed can still carry them — most notably a
 * legacy all-permission "Default role" handed to every self-registrant. Only
 * reads; it never changes anything.
 */
class AuditPrivilegeEscalation extends Command
{
    protected $signature = 'security:audit-escalation';

    protected $description = 'Report role/registration settings that could hand an outsider administrative access';

    private int $problems = 0;

    public function handle(): int
    {
        $settings = app(GeneralSettings::class);
        $totalPermissions = Permission::count();

        $this->line('');
        $this->info('Privilege escalation audit');
        $this->line(str_repeat('=', 60));

        $this->auditDefaultRole($settings, $totalPermissions);
        $this->auditSuperAdmins();
        $this->auditRegistration($settings);

        $this->line('');

        if ($this->problems === 0) {
            $this->info('No escalation risks found.');

            return self::SUCCESS;
        }

        $this->warn($this->problems.' issue(s) need attention. Nothing was changed.');

        return self::FAILURE;
    }

    private function auditDefaultRole(GeneralSettings $settings, int $totalPermissions): void
    {
        $this->line('');
        $this->line('<comment>Default role</comment> (given to every new registrant)');

        if (blank($settings->default_role)) {
            $this->line('  none — new users stay pending until an admin assigns a role. OK');

            return;
        }

        $role = Role::find($settings->default_role);

        if (! $role) {
            $this->line('  points at a role that no longer exists; nothing is assigned. OK');

            return;
        }

        $held = $role->permissions()->count();
        $this->line("  \"{$role->name}\" — {$held} of {$totalPermissions} permissions");

        if ($role->isSuperAdminRole()) {
            $this->flag('the default role IS the Super Admin role: every registrant would be an admin.');

            return;
        }

        // Anything that can hand out roles or permissions is a stepping stone
        // to everything else, so treat it as administrative.
        $escalating = $role->permissions()
            ->whereIn('name', [
                'Update role', 'Create role', 'Update user', 'Create user',
                'Manage super admin settings', 'Manage general settings',
            ])
            ->pluck('name');

        if ($escalating->isNotEmpty()) {
            $this->flag('the default role can manage roles/users/settings: '.$escalating->implode(', '));
        } elseif ($totalPermissions > 0 && $held === $totalPermissions) {
            $this->flag('the default role holds every permission in the system.');
        }
    }

    private function auditSuperAdmins(): void
    {
        $this->line('');
        $this->line('<comment>Super Admins</comment>');

        $admins = User::superAdmins()->get();
        $this->line('  '.$admins->count().' user(s) hold the Super Admin role');

        if ($admins->isEmpty()) {
            $this->flag('nobody is a Super Admin: no one can administer the platform.');

            return;
        }

        if (! config('system.security.require_2fa_for_super_admin')) {
            $this->flag('REQUIRE_2FA_FOR_SUPER_ADMIN is off, so admins may skip two-factor auth.');
        }

        $without2fa = $admins->reject->has_confirmed_two_factor;

        if ($without2fa->isNotEmpty()) {
            $this->flag($without2fa->count().' Super Admin(s) have not confirmed 2FA: '
                .$without2fa->pluck('email')->implode(', '));
        }
    }

    private function auditRegistration(GeneralSettings $settings): void
    {
        $this->line('');
        $this->line('<comment>Registration</comment>');

        $open = (bool) $settings->enable_registration;
        $social = (bool) $settings->enable_social_login;
        $allowList = config('filament-socialite.domain_allowlist', []);

        $this->line('  self-registration: '.($open ? 'enabled' : 'disabled'));
        $this->line('  social login: '.($social ? 'enabled' : 'disabled'));

        if ($social) {
            $this->line('  social email domains: '.(count($allowList) ? implode(', ', $allowList) : 'any'));
        }

        // Open sign-up only matters when it also grants something.
        if (($open || $social) && filled($settings->default_role)) {
            $this->line('  <comment>note</comment>: sign-up is open AND a default role is set — review the role above.');
        }

        if ($social && count($allowList) === 0) {
            $this->line('  <comment>note</comment>: any email domain may sign in socially. Set '
                .'filament-socialite.domain_allowlist to restrict it.');
        }
    }

    private function flag(string $message): void
    {
        $this->problems++;
        $this->line('  <fg=red>RISK</> '.$message);
    }
}

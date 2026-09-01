<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionsSeeder extends Seeder
{
    private array $modules = [
        'permission', 'project', 'project status', 'role', 'ticket',
        'ticket priority', 'ticket status', 'ticket type', 'user',
        'activity', 'sprint', 'label',
    ];

    private array $pluralActions = [
        'List',
    ];

    private array $singularActions = [
        'View', 'Create', 'Update', 'Delete',
    ];

    private array $extraPermissions = [
        'Manage general settings', 'Manage super admin settings',
        'Import from Jira',
        'List timesheet data', 'View timesheet dashboard',
        'View analytics',
    ];

    private string $superAdminRole = 'Super Admin';

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Create profiles
        foreach ($this->modules as $module) {
            $plural = Str::plural($module);
            $singular = $module;
            foreach ($this->pluralActions as $action) {
                Permission::firstOrCreate([
                    'name' => $action.' '.$plural,
                ]);
            }
            foreach ($this->singularActions as $action) {
                Permission::firstOrCreate([
                    'name' => $action.' '.$singular,
                ]);
            }
        }

        foreach ($this->extraPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
            ]);
        }

        // Super Admin role: holds every permission. This is the main admin's
        // role and is deliberately NOT used as the registration default.
        $superAdmin = Role::firstOrCreate([
            'name' => $this->superAdminRole,
        ]);
        $superAdmin->syncPermissions(Permission::all()->pluck('name')->toArray());

        // New registrants must not be granted a role automatically: leave the
        // default role unset so they stay pending until an admin assigns them a
        // role/package (see UserResource). Granting the all-permission role by
        // default would let anyone self-register into a full admin account.
        $settings = app(GeneralSettings::class);
        $settings->default_role = null;
        $settings->save();

        // Bootstrap: give the first user (the main admin) the Super Admin role.
        if ($user = User::first()) {
            $user->syncRoles([$this->superAdminRole]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * A ready-made, coherent "Employee" role for project workers: they can see and
 * work on tickets, sprints and projects they belong to, but not manage users,
 * roles or delete things. Gives admins a sane role to assign instead of
 * hand-picking granular permissions (and accidentally missing e.g. "View
 * ticket", which then 403s).
 */
class EmployeeRoleSeeder extends Seeder
{
    private array $permissions = [
        'List projects', 'View project',
        'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
        'List sprints', 'View sprint',
        'List ticket statuses', 'View ticket status',
        'List ticket types', 'View ticket type',
        'List ticket priorities', 'View ticket priority',
        'List activities', 'View activity',
        'List timesheet data', 'View timesheet dashboard',
        'View analytics',
    ];

    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'Employee']);

        // Only sync permissions that actually exist (created by PermissionsSeeder).
        $existing = Permission::whereIn('name', $this->permissions)->pluck('name')->all();
        $role->syncPermissions($existing);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Ready-made Access roles for Owner/Admin to pick when inviting/adding a
 * member (App\Filament\Pages\OrganizationSettings), matching the three
 * Organization role tiers (config('system.organizations.affectations.roles.list'):
 * owner/admin/member) by name - "Owner"/"Admin"/"Member" - so choosing an
 * Organization role can auto-select the matching Access role instead of
 * leaving Owner/Admin to build one by hand first. "Employee" already exists
 * (EmployeeRoleSeeder, the self-registration default, unrelated to any
 * particular Organization role) and is deliberately left untouched here.
 *
 * Deliberately NOT the 'role'/'permission'/'user' modules or 'Manage super
 * admin settings' for any of these three - those stay platform-wide,
 * Super-Admin-only concerns regardless of Organization role (see
 * [[subscription-model-direction]] on why Spatie Roles/Permissions/Users
 * are not Organization-scoped, and OrganizationSettings's own addMember
 * action, which excludes the Super Admin role from the picker outright).
 */
class OrganizationAccessRoleSeeder extends Seeder
{
    /**
     * Everything a Member can do: read-only across the board, plus logging
     * their own ticket work - deliberately the same shape as the existing
     * "Employee" role (EmployeeRoleSeeder), since both represent the same
     * "ordinary participant" tier under two different names.
     */
    private array $memberPermissions = [
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

    /**
     * Everything a Member can do, plus managing a project and this
     * Organization's own reference data (project statuses, ticket types/
     * priorities/statuses, labels, activities - the Fase 3B models an
     * active Admin would reasonably configure day to day).
     */
    private array $adminPermissions = [
        'List projects', 'View project', 'Create project', 'Update project', 'Delete project',
        'List tickets', 'View ticket', 'Create ticket', 'Update ticket', 'Delete ticket',
        'List sprints', 'View sprint',
        'List ticket statuses', 'View ticket status', 'Create ticket status', 'Update ticket status', 'Delete ticket status',
        'List ticket types', 'View ticket type', 'Create ticket type', 'Update ticket type', 'Delete ticket type',
        'List ticket priorities', 'View ticket priority', 'Create ticket priority', 'Update ticket priority', 'Delete ticket priority',
        'List project statuses', 'View project status', 'Create project status', 'Update project status', 'Delete project status',
        'List activities', 'View activity', 'Create activity', 'Update activity', 'Delete activity',
        'List labels', 'View label', 'Create label', 'Update label', 'Delete label',
        'List timesheet data', 'View timesheet dashboard',
        'View analytics',
    ];

    public function run(): void
    {
        $this->syncRole('Member', $this->memberPermissions);
        $this->syncRole('Admin', $this->adminPermissions);
        // The true "Owner can delete the organization/project, cannot be
        // demoted as the sole Owner" authority never runs through Spatie
        // permissions at all (Project::isOwnerManageableThroughOrganizationBy(),
        // OrganizationPolicy) - so this Access role exists purely to also
        // cover every feature permission Admin has, not to grant anything
        // beyond it.
        $this->syncRole('Owner', $this->adminPermissions);
    }

    private function syncRole(string $name, array $permissions): void
    {
        $role = Role::firstOrCreate(['name' => $name]);

        // Only sync permissions that actually exist (created by PermissionsSeeder).
        $existing = Permission::whereIn('name', $permissions)->pluck('name')->all();
        $role->syncPermissions($existing);
    }
}

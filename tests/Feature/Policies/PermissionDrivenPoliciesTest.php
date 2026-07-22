<?php

namespace Tests\Feature\Policies;

use App\Models\Activity;
use App\Models\Permission;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * The reference-data policies (users, roles, statuses, types, ...) are purely
 * permission-driven: no ownership rules. These cases assert both directions -
 * granted with the permission, denied without it - for every policy and every
 * ability, so a dropped permission check cannot slip through unnoticed.
 */
class PermissionDrivenPoliciesTest extends TestCase
{
    use RefreshDatabase, InteractsWithPermissions;

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public function policyProvider(): array
    {
        return [
            // key => [model class, ability, required permission]
            'user: list' => [User::class, 'viewAny', 'List users'],
            'user: view' => [User::class, 'view', 'View user'],
            'user: create' => [User::class, 'create', 'Create user'],
            'user: update' => [User::class, 'update', 'Update user'],
            'user: delete' => [User::class, 'delete', 'Delete user'],

            'role: list' => [Role::class, 'viewAny', 'List roles'],
            'role: view' => [Role::class, 'view', 'View role'],
            'role: create' => [Role::class, 'create', 'Create role'],
            'role: update' => [Role::class, 'update', 'Update role'],
            'role: delete' => [Role::class, 'delete', 'Delete role'],

            'permission: list' => [Permission::class, 'viewAny', 'List permissions'],
            'permission: view' => [Permission::class, 'view', 'View permission'],
            'permission: create' => [Permission::class, 'create', 'Create permission'],
            'permission: update' => [Permission::class, 'update', 'Update permission'],
            'permission: delete' => [Permission::class, 'delete', 'Delete permission'],

            'activity: list' => [Activity::class, 'viewAny', 'List activities'],
            'activity: view' => [Activity::class, 'view', 'View activity'],
            'activity: create' => [Activity::class, 'create', 'Create activity'],
            'activity: update' => [Activity::class, 'update', 'Update activity'],
            'activity: delete' => [Activity::class, 'delete', 'Delete activity'],

            'project status: list' => [ProjectStatus::class, 'viewAny', 'List project statuses'],
            'project status: view' => [ProjectStatus::class, 'view', 'View project status'],
            'project status: create' => [ProjectStatus::class, 'create', 'Create project status'],
            'project status: update' => [ProjectStatus::class, 'update', 'Update project status'],
            'project status: delete' => [ProjectStatus::class, 'delete', 'Delete project status'],

            'ticket status: list' => [TicketStatus::class, 'viewAny', 'List ticket statuses'],
            'ticket status: view' => [TicketStatus::class, 'view', 'View ticket status'],
            'ticket status: create' => [TicketStatus::class, 'create', 'Create ticket status'],
            'ticket status: update' => [TicketStatus::class, 'update', 'Update ticket status'],
            'ticket status: delete' => [TicketStatus::class, 'delete', 'Delete ticket status'],

            'ticket type: list' => [TicketType::class, 'viewAny', 'List ticket types'],
            'ticket type: view' => [TicketType::class, 'view', 'View ticket type'],
            'ticket type: create' => [TicketType::class, 'create', 'Create ticket type'],
            'ticket type: update' => [TicketType::class, 'update', 'Update ticket type'],
            'ticket type: delete' => [TicketType::class, 'delete', 'Delete ticket type'],

            'ticket priority: list' => [TicketPriority::class, 'viewAny', 'List ticket priorities'],
            'ticket priority: view' => [TicketPriority::class, 'view', 'View ticket priority'],
            'ticket priority: create' => [TicketPriority::class, 'create', 'Create ticket priority'],
            'ticket priority: update' => [TicketPriority::class, 'update', 'Update ticket priority'],
            'ticket priority: delete' => [TicketPriority::class, 'delete', 'Delete ticket priority'],
        ];
    }

    /**
     * Build the argument the ability expects: a class name for the
     * "any"-style abilities, a persisted record for the rest.
     */
    private function subjectFor(string $model, string $ability): mixed
    {
        if (in_array($ability, ['viewAny', 'create'], true)) {
            return $model;
        }

        return match ($model) {
            Role::class => Role::create(['name' => 'subject_' . uniqid()]),
            Permission::class => Permission::create(['name' => 'subject_' . uniqid()]),
            default => $model::factory()->create(),
        };
    }

    /**
     * @dataProvider policyProvider
     */
    public function test_ability_is_granted_with_the_permission(
        string $model,
        string $ability,
        string $permission
    ): void {
        $user = $this->userWithPermissions([$permission]);

        $this->assertTrue(
            $user->can($ability, $this->subjectFor($model, $ability)),
            "$ability on $model should be granted with '$permission'."
        );
    }

    /**
     * @dataProvider policyProvider
     */
    public function test_ability_is_denied_without_the_permission(
        string $model,
        string $ability,
        string $permission
    ): void {
        $user = $this->userWithoutPermissions();

        $this->assertFalse(
            $user->can($ability, $this->subjectFor($model, $ability)),
            "$ability on $model should be denied without '$permission'."
        );
    }
}

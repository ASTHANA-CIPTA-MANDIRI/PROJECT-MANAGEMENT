<?php

namespace Tests\Feature\Policies;

use App\Models\Project;
use App\Models\TicketStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Filament ships two "allow by default" shortcuts: Resource::can() and
 * RelationManager::can() both return true when the model has no policy, and
 * again when the policy has no method for the ability being asked about. An
 * ability nobody wrote a method for is therefore granted to everyone - silently,
 * and only for the panel, since Laravel's own Gate denies the same call.
 *
 * AuthServiceProvider flips both classes to gate-based authorization so they
 * fail closed. These cases hold that line: the flip stays on, and every ability
 * the panel actually asks for is backed by a real policy method - otherwise
 * failing closed would quietly break the UI instead of quietly opening it.
 */
class FilamentAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    /**
     * Abilities each relation manager asks of its *related* model's policy,
     * derived from the actions its table declares.
     *
     * @var array<class-string, array<int, string>>
     */
    private const RELATION_MANAGER_ABILITIES = [
        \App\Filament\Resources\ProjectResource\RelationManagers\StatusesRelationManager::class => [
            'viewAny', 'create', 'update', 'delete', 'deleteAny',
        ],
        \App\Filament\Resources\ProjectResource\RelationManagers\SprintsRelationManager::class => [
            'viewAny', 'create', 'update', 'delete', 'deleteAny',
        ],
        \App\Filament\Resources\ProjectResource\RelationManagers\UsersRelationManager::class => [
            'viewAny', 'create', 'update', 'delete', 'deleteAny', 'attach', 'detach', 'detachAny',
        ],
    ];

    public function test_filament_authorization_fails_closed(): void
    {
        $this->assertTrue(
            Resource::shouldAuthorizeWithGate(),
            'Resource authorization fell back to policy discovery, which grants any ability '
            .'a policy has no method for.'
        );

        $this->assertTrue(
            RelationManager::shouldAuthorizeWithGate(),
            'RelationManager authorization fell back to policy discovery, which grants any '
            .'ability a policy has no method for.'
        );

        $this->assertFalse(Resource::shouldIgnorePolicies());
        $this->assertFalse(RelationManager::shouldIgnorePolicies());
    }

    /**
     * Abilities every resource page reaches for, whatever the resource.
     *
     * @var array<int, string>
     */
    private const RESOURCE_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];

    /**
     * Resources whose table declares `->reorderable()`, which makes the panel ask
     * for one extra ability. Kept as a list so adding `->reorderable()` to another
     * resource without a matching policy method shows up as a failure here.
     *
     * @var array<int, class-string>
     */
    private const REORDERABLE_RESOURCES = [
        \App\Filament\Resources\TicketStatusResource::class,
    ];

    public function test_every_resource_ability_has_a_policy_method(): void
    {
        $resources = Filament::getResources();

        $this->assertNotEmpty($resources, 'No Filament resources were discovered.');

        foreach ($resources as $resource) {
            $abilities = self::RESOURCE_ABILITIES;

            if (in_array($resource, self::REORDERABLE_RESOURCES, true)) {
                $abilities[] = 'reorder';
            }

            foreach ($abilities as $ability) {
                $this->assertPolicyHandles($resource::getModel(), $ability, $resource);
            }
        }
    }

    public function test_the_reorderable_resource_list_matches_the_tables(): void
    {
        foreach (Filament::getResources() as $resource) {
            $declares = str_contains(
                file_get_contents((new \ReflectionClass($resource))->getFileName()),
                '->reorderable('
            );

            $this->assertSame(
                in_array($resource, self::REORDERABLE_RESOURCES, true),
                $declares,
                "{$resource} declares ->reorderable() but is not in REORDERABLE_RESOURCES (or "
                .'the other way round), so its reorder ability is not being checked.'
            );
        }
    }

    public function test_every_relation_manager_ability_has_a_policy_method(): void
    {
        foreach (self::RELATION_MANAGER_ABILITIES as $manager => $abilities) {
            // Resolve the related model off the Project relationship itself rather
            // than naming it here, so a retargeted relation manager is followed.
            $property = (new \ReflectionClass($manager))->getProperty('relationship');
            $property->setAccessible(true);

            $related = get_class((new Project)->{$property->getValue()}()->getModel());

            foreach ($abilities as $ability) {
                $this->assertPolicyHandles($related, $ability, $manager);
            }
        }
    }

    private function assertPolicyHandles(string $model, string $ability, ?string $context = null): void
    {
        $policy = Gate::getPolicyFor($model);
        $where = $context ? " (asked by {$context})" : '';

        $this->assertNotNull($policy, "{$model} has no policy{$where}.");

        $this->assertTrue(
            method_exists($policy, $ability),
            get_class($policy)."::{$ability}() is missing{$where}. With gate-based authorization "
            .'the panel now denies this ability outright; add the method so the rule is stated '
            .'explicitly.'
        );
    }

    public function test_reordering_ticket_statuses_requires_the_update_permission(): void
    {
        $status = TicketStatus::factory()->create();

        $lister = $this->userWithPermissions(['List ticket statuses']);
        $this->assertFalse(
            $lister->can('reorder', TicketStatus::class),
            'A user who may only list ticket statuses was allowed to reorder them, which '
            .'rewrites the board column order for the whole instance.'
        );

        $editor = $this->userWithPermissions(['List ticket statuses', 'Update ticket status']);
        $this->assertTrue($editor->can('reorder', TicketStatus::class));
        $this->assertTrue($editor->can('update', $status));
    }

    public function test_attaching_project_members_requires_the_project_update_permission(): void
    {
        $outsider = $this->userWithPermissions(['List users']);
        $this->assertFalse($outsider->can('attach', User::class));
        $this->assertFalse($outsider->can('detachAny', User::class));

        $manager = $this->userWithPermissions(['List users', 'Update project']);
        $this->assertTrue($manager->can('attach', User::class));
        $this->assertTrue($manager->can('detach', $outsider));
        $this->assertTrue($manager->can('detachAny', User::class));
    }
}

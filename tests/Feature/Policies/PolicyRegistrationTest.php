<?php

namespace Tests\Feature\Policies;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Authorization in this app fails open: Filament's `Resource::can()` returns
 * true when `Gate::getPolicyFor()` finds no policy for the resource model. So a
 * model that nobody remembered to guard is not merely unprotected - it is fully
 * open to every authenticated user, silently.
 *
 * These cases pin the explicit map in AuthServiceProvider to reality: every
 * model is either guarded or listed as deliberately exempt, every policy class
 * is wired up, and no Filament resource sits on an unguarded model.
 */
class PolicyRegistrationTest extends TestCase
{
    /**
     * Models with no policy of their own. Each is a pivot or child row that is
     * only ever reached through a guarded parent (Project or Ticket), so its
     * authorization lives in that parent's policy.
     *
     * @var array<int, string>
     */
    private const UNGUARDED_MODELS = [
        \App\Models\ProjectFavorite::class,
        \App\Models\ProjectUser::class,
        \App\Models\TicketActivity::class,
        \App\Models\TicketRelation::class,
        \App\Models\TicketSubscriber::class,
    ];

    /**
     * @return array<int, class-string>
     */
    private function classesIn(string $directory, string $namespace): array
    {
        return collect(glob(app_path($directory.'/*.php')))
            ->map(fn (string $path) => $namespace.'\\'.basename($path, '.php'))
            ->sort()
            ->values()
            ->all();
    }

    public function test_every_model_is_guarded_or_explicitly_exempt(): void
    {
        $registered = array_keys(Gate::policies());

        foreach ($this->classesIn('Models', 'App\Models') as $model) {
            $this->assertTrue(
                in_array($model, $registered, true) || in_array($model, self::UNGUARDED_MODELS, true),
                "{$model} has no policy in AuthServiceProvider::\$policies. Register one, or "
                .'add it to PolicyRegistrationTest::UNGUARDED_MODELS with a reason - an '
                .'unguarded model is authorized by default in Filament.'
            );
        }
    }

    public function test_every_policy_class_is_registered(): void
    {
        $registered = array_values(Gate::policies());

        foreach ($this->classesIn('Policies', 'App\Policies') as $policy) {
            $this->assertContains(
                $policy,
                $registered,
                "{$policy} exists but is not mapped in AuthServiceProvider::\$policies, so it "
                .'guards nothing.'
            );
        }
    }

    public function test_registered_models_resolve_to_their_mapped_policy(): void
    {
        foreach (Gate::policies() as $model => $policy) {
            $this->assertInstanceOf(
                $policy,
                Gate::getPolicyFor($model),
                "{$model} does not resolve to {$policy}."
            );
        }
    }

    public function test_no_model_is_registered_twice_under_a_different_policy(): void
    {
        $this->assertSame(
            array_unique(array_values(Gate::policies())),
            array_values(Gate::policies()),
            'A policy class guards more than one model; check AuthServiceProvider::$policies '
            .'for a copy-paste slip.'
        );
    }

    public function test_every_filament_resource_model_is_guarded(): void
    {
        $resources = Filament::getResources();

        $this->assertNotEmpty($resources, 'No Filament resources were discovered.');

        foreach ($resources as $resource) {
            $model = $resource::getModel();

            $this->assertNotNull(
                Gate::getPolicyFor($model),
                "{$resource} exposes {$model} without a policy. Filament grants every ability "
                .'on an unguarded model, so this resource would be open to any authenticated user.'
            );
        }
    }

    public function test_the_exempt_models_are_real_and_still_unguarded(): void
    {
        foreach (self::UNGUARDED_MODELS as $model) {
            $this->assertTrue(class_exists($model), "{$model} is listed as exempt but no longer exists.");

            $this->assertNull(
                Gate::getPolicyFor($model),
                "{$model} now has a policy; drop it from PolicyRegistrationTest::UNGUARDED_MODELS."
            );
        }

        $this->assertSame(
            self::UNGUARDED_MODELS,
            array_values(array_filter(
                self::UNGUARDED_MODELS,
                fn (string $model) => Str::startsWith($model, 'App\Models\\')
            )),
            'Only application models belong in the exempt list.'
        );
    }
}

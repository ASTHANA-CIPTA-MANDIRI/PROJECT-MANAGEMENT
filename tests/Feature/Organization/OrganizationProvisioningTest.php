<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6 — a self-registered user gets their own Organization automatically,
 * as Owner, on a 7-day trial. Drives the real Registered event rather than
 * calling the listener directly, the same style RegistrationWorkflowTest
 * already uses for role assignment.
 */
class OrganizationProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_a_personal_organization_with_the_user_as_owner(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso']);

        event(new Registered($user));

        $organization = $user->fresh()->organizations()->sole();

        $this->assertSame('owner', $organization->roleOf($user->fresh()));
    }

    public function test_the_provisioned_organization_gets_a_seven_day_trial(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $organization = $user->fresh()->organizations()->sole();

        $this->assertNotNull($organization->trial_ends_at);
        $this->assertTrue($organization->trial_ends_at->isFuture());
        $this->assertTrue($organization->trial_ends_at->diffInHours(now()->addDays(7)) < 1);
    }

    public function test_the_organization_name_is_derived_from_the_users_name(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso']);

        event(new Registered($user));

        $this->assertDatabaseHas('organizations', ['name' => "Budi Santoso's Organization"]);
    }

    /**
     * Two users with the same name must not collide on organizations.name's
     * unique index — the second gets a numbered suffix instead of failing
     * registration outright.
     */
    public function test_a_duplicate_organization_name_is_disambiguated_with_a_suffix(): void
    {
        $first = User::factory()->create(['name' => 'Budi Santoso']);
        event(new Registered($first));

        $second = User::factory()->create(['name' => 'Budi Santoso']);
        event(new Registered($second));

        $this->assertDatabaseHas('organizations', ['name' => "Budi Santoso's Organization"]);
        $this->assertDatabaseHas('organizations', ['name' => "Budi Santoso's Organization (2)"]);
    }

    /**
     * Re-firing Registered for a user who already has an Organization (a
     * duplicate dispatch, or one who was invited into an Organization
     * between account creation and this handler running) must not create a
     * second one — Option B limits a user to exactly one Organization.
     */
    public function test_a_user_who_already_belongs_to_an_organization_does_not_get_a_second_one(): void
    {
        $user = User::factory()->create();
        $existing = Organization::factory()->create();
        $existing->users()->attach($user->id, ['role' => 'member']);

        event(new Registered($user));

        $this->assertSame(1, $user->fresh()->organizations()->count());
    }
}

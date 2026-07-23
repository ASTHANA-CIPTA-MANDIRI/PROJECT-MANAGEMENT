<?php

namespace Tests\Feature;

use App\Listeners\AssignDefaultRole;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every registration path must leave the user with a role, otherwise
 * User::canAccessFilament() locks them out of the panel with a 403.
 *
 * GeneralSettings is faked rather than persisted: the settings table has no
 * unique index on (group, name), so its upsert cannot run on SQLite.
 */
class RegistrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function withDefaultRole(?Role $role): void
    {
        GeneralSettings::fake(['default_role' => $role?->id]);
    }

    public function test_registering_assigns_the_configured_default_role(): void
    {
        $this->withDefaultRole(Role::create(['name' => 'Default role']));

        $user = User::factory()->create();
        event(new Registered($user));

        $this->assertTrue($user->fresh()->hasRole('Default role'));
    }

    public function test_a_registered_user_can_reach_the_panel(): void
    {
        $this->withDefaultRole(Role::create(['name' => 'Default role']));

        $user = User::factory()->create();
        $this->assertFalse($user->canAccessFilament(), 'sanity: no role yet');

        event(new Registered($user));

        $this->assertTrue($user->fresh()->canAccessFilament());
    }

    public function test_registration_does_not_override_an_existing_role(): void
    {
        $this->withDefaultRole(Role::create(['name' => 'Default role']));
        $special = Role::create(['name' => 'Manager']);

        $user = User::factory()->create();
        $user->syncRoles([$special]);

        event(new Registered($user));

        $user->refresh();
        $this->assertTrue($user->hasRole('Manager'));
        $this->assertFalse($user->hasRole('Default role'));
    }

    public function test_registration_is_safe_when_no_default_role_is_configured(): void
    {
        $this->withDefaultRole(null);

        $user = User::factory()->create();

        // Must not throw, even though there is nothing to assign.
        (new AssignDefaultRole)->handle(new Registered($user));

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_registration_is_safe_when_the_default_role_no_longer_exists(): void
    {
        $role = Role::create(['name' => 'Temporary']);
        $this->withDefaultRole($role);
        $role->delete();

        $user = User::factory()->create();

        (new AssignDefaultRole)->handle(new Registered($user));

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_the_listener_is_wired_to_the_registered_event(): void
    {
        $this->withDefaultRole(Role::create(['name' => 'Default role']));

        $user = User::factory()->create();
        // Fired through the real dispatcher, not by calling the listener.
        event(new Registered($user));

        $this->assertTrue($user->fresh()->hasRole('Default role'));
    }

    public function test_a_user_keeps_panel_access_only_while_holding_a_role(): void
    {
        $this->withDefaultRole(Role::create(['name' => 'Default role']));

        $user = User::factory()->create();
        event(new Registered($user));
        $this->assertTrue($user->fresh()->canAccessFilament());

        $user->syncRoles([]);

        $this->assertFalse($user->fresh()->canAccessFilament());
    }
}

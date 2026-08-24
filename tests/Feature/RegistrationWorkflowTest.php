<?php

namespace Tests\Feature;

use App\Listeners\AssignDefaultRole;
use App\Listeners\NotifyAdminsOfRegistration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomVerifyEmail;
use App\Notifications\NewUserPendingApproval;
use App\Settings\GeneralSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
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

    public function test_the_super_admin_role_is_never_auto_assigned(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        GeneralSettings::fake([
            'default_role' => $superAdminRole->id,
            'super_admin_role' => null, // falls back to the role named "Super Admin"
        ]);

        $user = User::factory()->create();
        event(new Registered($user));

        $this->assertCount(
            0,
            $user->fresh()->roles,
            'a self-registrant must never be auto-granted the Super Admin role'
        );
    }

    public function test_the_configured_super_admin_role_is_never_auto_assigned(): void
    {
        $role = Role::create(['name' => 'Owners']);
        GeneralSettings::fake([
            'default_role' => $role->id,
            'super_admin_role' => (string) $role->id,
        ]);

        $user = User::factory()->create();
        event(new Registered($user));

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

    // ------------------------------------------------ admin approval workflow

    private function adminWhoCanApprove(): User
    {
        Permission::firstOrCreate(['name' => 'Update user']);
        $role = Role::create(['name' => 'Approver_'.uniqid()]);
        $role->syncPermissions(['Update user']);

        $admin = User::factory()->create();
        $admin->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    public function test_admins_are_notified_of_a_new_registrant(): void
    {
        $admin = $this->adminWhoCanApprove();
        $registrant = User::factory()->create();

        (new NotifyAdminsOfRegistration)->handle(new Registered($registrant));

        Notification::assertSentTo($admin, NewUserPendingApproval::class);
        Notification::assertNotSentTo($registrant, NewUserPendingApproval::class);
    }

    public function test_the_admin_notification_listener_is_wired_to_registration(): void
    {
        $admin = $this->adminWhoCanApprove();
        $this->withDefaultRole(null);

        event(new Registered(User::factory()->create()));

        Notification::assertSentTo($admin, NewUserPendingApproval::class);
    }

    public function test_a_registrant_receives_the_branded_verification_mail(): void
    {
        // AppServiceProvider used to register a VerifyEmail::toMailUsing
        // fallback to brand this mail, but it never fired: User overrides
        // sendEmailVerificationNotification() to send CustomVerifyEmail
        // directly. The branding has to hold without that fallback.
        $this->withDefaultRole(null);
        $user = User::factory()->unverified()->create();

        event(new Registered($user));

        Notification::assertSentTo(
            $user,
            CustomVerifyEmail::class,
            fn ($notification) => $notification->toMail($user)->salutation === __('Thanks, Rencanakan Team')
        );
    }

    public function test_a_pending_user_sees_the_awaiting_approval_page(): void
    {
        $this->actingAs(User::factory()->create()); // no role → pending

        $html = strtolower(view('errors.403')->render());

        $this->assertStringContainsString(strtolower(__('Your account is awaiting approval')), $html);
    }

    public function test_a_user_with_a_role_sees_the_generic_forbidden_page(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Member'])]);
        $this->actingAs($user->fresh());

        $html = strtolower(view('errors.403')->render());

        $this->assertStringNotContainsString(strtolower(__('Your account is awaiting approval')), $html);
        $this->assertStringContainsString('403', $html);
    }
}

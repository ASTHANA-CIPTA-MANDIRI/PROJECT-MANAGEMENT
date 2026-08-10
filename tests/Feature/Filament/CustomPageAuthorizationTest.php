<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\AuthorizedPage;
use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Pages\JiraImport;
use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Pages\TimesheetDashboard;
use App\Filament\Pages\TimesheetExport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * shouldRegisterNavigation() only hides a menu entry — the route stays
 * registered and the Livewire component stays mountable. Every custom page must
 * therefore enforce its permission server-side; these tests pin that down for
 * each page and guard against a new page shipping without the pattern.
 */
class CustomPageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * A panel user holding exactly the given permissions (and no more).
     */
    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Role '.(Role::count() + 1)]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Livewire::test() proxies unknown assertions to the underlying response,
     * so a 403 raised while mounting the component surfaces here.
     */
    private function assertMountForbidden(string $page): void
    {
        Livewire::test($page)->assertForbidden();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function protectedPages(): array
    {
        return [
            'general settings' => [ManageGeneralSettings::class, 'Manage general settings'],
            'timesheet dashboard' => [TimesheetDashboard::class, 'View timesheet dashboard'],
            'timesheet export' => [TimesheetExport::class, 'List timesheet data'],
            'analytics' => [Analytics::class, 'View analytics'],
            'jira import' => [JiraImport::class, 'Import from Jira'],
        ];
    }

    // ------------------------------------------------------- every page

    /**
     * @dataProvider protectedPages
     */
    public function test_a_page_declares_the_permission_it_requires(string $page, string $permission): void
    {
        $this->assertSame($permission, $page::requiredPermission());
    }

    /**
     * @dataProvider protectedPages
     */
    public function test_mounting_a_page_without_its_permission_is_forbidden(string $page): void
    {
        // Holds an unrelated permission, so this is not merely "no permissions".
        $this->actingAs($this->userWith(['List projects']));

        $this->assertMountForbidden($page);
    }

    /**
     * @dataProvider protectedPages
     */
    public function test_opening_a_page_url_directly_without_its_permission_is_forbidden(string $page, string $permission): void
    {
        $this->actingAs($this->userWith(['List projects']));

        $this->get($page::getUrl())->assertForbidden();
    }

    /**
     * @dataProvider protectedPages
     */
    public function test_a_page_opens_for_a_user_holding_its_permission(string $page, string $permission): void
    {
        $this->actingAs($this->userWith([$permission]));

        Livewire::test($page)->assertSuccessful();
    }

    /**
     * @dataProvider protectedPages
     */
    public function test_hiding_the_navigation_entry_is_not_the_authorization(string $page, string $permission): void
    {
        $this->actingAs($this->userWith(['List projects']));

        // The menu entry is hidden...
        $shouldRegister = (new ReflectionClass($page))->getMethod('shouldRegisterNavigation');
        $shouldRegister->setAccessible(true);
        $this->assertFalse($shouldRegister->invoke(null));

        // ...but the route is still registered, so the page itself must refuse.
        $this->assertNotNull($page::getUrl());
        $this->assertMountForbidden($page);
    }

    /**
     * Authorization runs in boot(), which fires on every Livewire request — not
     * only on mount — so a page action cannot be called past a denied mount.
     */
    public function test_a_page_action_cannot_be_called_without_the_permission(): void
    {
        $this->actingAs($this->userWith(['List timesheet data']));

        $export = Livewire::test(TimesheetExport::class);

        // Same mounted component, now driven by a user without the permission.
        $this->actingAs($this->userWith(['List projects']));

        $export->call('create')->assertForbidden();
    }

    // --------------------------------------------- super admin settings

    public function test_a_settings_manager_cannot_repoint_the_super_admin_role(): void
    {
        $attacker = $this->userWith(['Manage general settings']);
        $ownRole = $attacker->roles()->first();
        $this->actingAs($attacker);

        $original = app(GeneralSettings::class)->super_admin_role;

        Livewire::test(ManageGeneralSettings::class)
            // A crafted payload: the field is not even rendered for this user.
            ->set('data.super_admin_role', (string) $ownRole->id)
            ->call('save')
            ->assertSuccessful();

        $this->assertSame($original, $this->freshSettings()->super_admin_role);
        $this->assertFalse(
            $attacker->fresh()->isSuperAdmin(),
            'a settings manager must not be able to promote their own role to Super Admin'
        );
    }

    public function test_the_super_admin_field_is_hidden_without_the_dedicated_permission(): void
    {
        $this->actingAs($this->userWith(['Manage general settings']));

        Livewire::test(ManageGeneralSettings::class)
            ->assertSuccessful()
            ->assertDontSee('Super Admin role');
    }

    public function test_a_super_admin_settings_manager_can_repoint_the_role(): void
    {
        $admin = $this->userWith([
            'Manage general settings',
            ManageGeneralSettings::SUPER_ADMIN_PERMISSION,
        ]);
        $this->actingAs($admin);

        $target = Role::create(['name' => 'Owner']);

        Livewire::test(ManageGeneralSettings::class)
            ->set('data.super_admin_role', (string) $target->id)
            ->call('save')
            ->assertSuccessful();

        $this->assertSame((string) $target->id, $this->freshSettings()->super_admin_role);
    }

    public function test_a_settings_manager_cannot_make_the_super_admin_role_the_default_role(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $this->actingAs($this->userWith(['Manage general settings']));

        Livewire::test(ManageGeneralSettings::class)
            ->set('data.default_role', (string) $superAdminRole->id)
            ->call('save')
            ->assertHasErrors('data.default_role');

        $this->assertNull($this->freshSettings()->default_role);
    }

    public function test_a_settings_manager_can_still_pick_an_ordinary_default_role(): void
    {
        $role = Role::create(['name' => 'Employee']);
        $this->actingAs($this->userWith(['Manage general settings']));

        Livewire::test(ManageGeneralSettings::class)
            ->set('data.default_role', (string) $role->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame((string) $role->id, $this->freshSettings()->default_role);
    }

    public function test_other_general_settings_still_save_without_the_super_admin_permission(): void
    {
        $this->actingAs($this->userWith(['Manage general settings']));

        Livewire::test(ManageGeneralSettings::class)
            ->set('data.site_name', 'Rencanakan QA')
            ->call('save')
            ->assertSuccessful();

        $this->assertSame('Rencanakan QA', $this->freshSettings()->site_name);
    }

    private function freshSettings(): GeneralSettings
    {
        app()->forgetInstance(GeneralSettings::class);

        return app(GeneralSettings::class);
    }

    // ------------------------------------------------- regression guard

    /**
     * Every custom page must go through the shared concern, so a page added
     * later cannot silently ship with navigation-only "protection".
     */
    public function test_every_custom_page_uses_the_shared_authorization_concern(): void
    {
        $pages = collect(glob(app_path('Filament/Pages/*.php')))
            ->map(fn (string $file) => 'App\\Filament\\Pages\\'.basename($file, '.php'))
            ->reject(fn (string $class) => (new ReflectionClass($class))->isAbstract());

        $this->assertNotEmpty($pages);

        foreach ($pages as $class) {
            $this->assertTrue(
                is_subclass_of($class, AuthorizedPage::class)
                    || in_array(AuthorizesPageAccess::class, class_uses_recursive($class), true),
                $class.' must extend AuthorizedPage or use AuthorizesPageAccess.'
            );
            $this->assertTrue(method_exists($class, 'requiredPermission'));
        }
    }
}

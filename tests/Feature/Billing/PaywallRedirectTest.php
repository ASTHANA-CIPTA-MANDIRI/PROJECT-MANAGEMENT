<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\RedirectLockedOrganizationToBilling;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The paywall: full-page GET navigation of a member of a locked Organization
 * (trial over, no active subscription) is redirected to /organization/billing.
 * Everything is exercised over real HTTP through the Filament `auth`
 * middleware group, because that is where the middleware is registered; the
 * request kinds the middleware must leave alone (non-GET, JSON, Livewire) are
 * driven through its handle() directly, since Livewire message requests never
 * pass that group in the first place.
 */
class PaywallRedirectTest extends TestCase
{
    use RefreshDatabase;

    private string $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['subscriptions.paywall_enabled' => true, 'subscriptions.checkout_enabled' => true]);
        $this->billing = route('filament.pages.organization/billing');
    }

    /** A verified user holding some Spatie role: the panel itself requires one (User::canAccessFilament()). */
    private function panelUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([Role::firstOrCreate(['name' => 'Panel user'])]);

        return $user->fresh();
    }

    private function memberOf(Organization $organization, string $role): User
    {
        $user = $this->panelUser();
        $organization->users()->attach($user->id, ['role' => $role]);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    // ------------------------------------------------------------ redirect

    public function test_a_locked_organizations_owner_admin_and_member_are_sent_to_billing(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        foreach (['owner', 'admin', 'member'] as $role) {
            $user = $this->memberOf($organization, $role);

            foreach (['/', '/projects', '/tickets', '/analytics'] as $path) {
                $this->actingAs($user)->get($path)->assertRedirect($this->billing);
            }
        }
    }

    public function test_a_super_admin_is_never_redirected_even_inside_a_locked_organization(): void
    {
        // The two-factor policy for Super Admins is a different redirect; keep it out of the way.
        config(['system.security.require_2fa_for_super_admin' => false]);
        $organization = Organization::factory()->trialExpired()->create();
        $admin = $this->superAdmin();
        $organization->users()->attach($admin->id, ['role' => 'owner']);
        OrganizationContext::switch($admin, $organization->id);

        $this->actingAs($admin)->get('/')->assertSuccessful();
        // Not redirected; what the Gate itself says about the page is another matter (TrialGate has no Super Admin bypass).
        $this->assertNotSame($this->billing, $this->actingAs($admin)->get('/projects')->headers->get('Location'));
    }

    public function test_users_who_are_not_locked_are_not_redirected(): void
    {
        $withoutOrganization = $this->panelUser();
        $grandfathered = $this->memberOf(Organization::factory()->create(['trial_ends_at' => null]), 'owner');
        $inTrial = $this->memberOf(Organization::factory()->create(['trial_ends_at' => now()->addDays(2)]), 'owner');

        $paidOrganization = Organization::factory()->trialExpired()->create();
        Subscription::factory()->active()->create(['organization_id' => $paidOrganization->id]);
        $subscribed = $this->memberOf($paidOrganization, 'member');

        foreach ([$withoutOrganization, $grandfathered, $inTrial, $subscribed] as $user) {
            $this->actingAs($user)->get('/')->assertSuccessful();
        }
    }

    public function test_access_is_restored_without_a_redirect_once_a_subscription_is_active(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $owner = $this->memberOf($organization, 'owner');

        $this->actingAs($owner)->get('/')->assertRedirect($this->billing);

        Subscription::factory()->active()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner)->get('/')->assertSuccessful();
    }

    public function test_an_expired_subscription_does_not_lift_the_lock(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        Subscription::factory()->create(['organization_id' => $organization->id, 'starts_at' => now()->subDays(40), 'ends_at' => now()->subDay()]);

        $this->actingAs($this->memberOf($organization, 'owner'))->get('/')->assertRedirect($this->billing);
    }

    public function test_the_lock_follows_the_current_organization_not_just_any_membership(): void
    {
        $locked = Organization::factory()->trialExpired()->create();
        $open = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $user = $this->memberOf($locked, 'owner');
        $open->users()->attach($user->id, ['role' => 'member']);

        OrganizationContext::switch($user, $open->id);
        $this->actingAs($user)->get('/')->assertSuccessful();

        OrganizationContext::switch($user, $locked->id);
        $this->actingAs($user)->get('/')->assertRedirect($this->billing);
    }

    // ----------------------------------------------------------- exemptions

    public function test_the_exempt_routes_open_for_a_locked_member_without_a_redirect_loop(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        foreach (['owner', 'member'] as $role) {
            $user = $this->memberOf($organization, $role);

            foreach (['/organization/billing', '/organization', '/my-profile'] as $path) {
                $this->actingAs($user)->get($path)->assertSuccessful();
            }

            // create-organization needs zero memberships, so it is a 403 for
            // someone who has one - but never a redirect to billing.
            $response = $this->actingAs($user)->get('/create-organization');
            $this->assertNotSame($this->billing, $response->headers->get('Location'));
        }
    }

    public function test_logout_still_works_for_a_locked_member(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        $response = $this->actingAs($this->memberOf($organization, 'owner'))->post(route('filament.auth.logout'));

        $this->assertNotSame($this->billing, $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_non_get_json_and_livewire_requests_are_passed_through(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->memberOf($organization, 'owner');
        $middleware = new RedirectLockedOrganizationToBilling;
        $next = fn () => response('next');

        $requests = [
            'post' => Request::create('/projects', 'POST'),
            'put' => Request::create('/projects', 'PUT'),
            'json' => Request::create('/projects', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
            'livewire' => Request::create('/projects', 'GET', server: ['HTTP_X_LIVEWIRE' => 'true']),
        ];

        foreach ($requests as $label => $request) {
            $request->setUserResolver(fn () => $user);
            $this->assertSame('next', $middleware->handle($request, $next)->getContent(), $label);
        }

        // Control: the same user on a plain GET is redirected by the very same instance.
        $plain = Request::create('/projects', 'GET');
        $plain->setUserResolver(fn () => $user);
        $this->assertTrue($middleware->handle($plain, $next)->isRedirect($this->billing));
    }

    // -------------------------------------------------------------- switches

    public function test_with_the_paywall_off_nobody_is_redirected(): void
    {
        config(['subscriptions.paywall_enabled' => false]);
        $owner = $this->memberOf(Organization::factory()->trialExpired()->create(), 'owner');

        $this->actingAs($owner)->get('/')->assertSuccessful();
    }

    public function test_with_checkout_off_the_paywall_counts_as_off(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        $owner = $this->memberOf(Organization::factory()->trialExpired()->create(), 'owner');

        $this->assertFalse(RedirectLockedOrganizationToBilling::paywallActive());
        $this->actingAs($owner)->get('/')->assertSuccessful();
    }

    public function test_the_switch_defaults_follow_the_environment(): void
    {
        $config = require base_path('config/subscriptions.php');

        // Test environment (APP_ENV=testing, not production): on by default.
        $this->assertTrue($config['paywall_enabled']);
        $this->assertTrue($config['checkout_enabled']);
    }

    // ------------------------------------------------------------------ API

    private function apiUser(Organization $organization): User
    {
        foreach (['View project', 'Create project', 'View ticket'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions(['View project', 'Create project', 'View ticket']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_api_of_a_locked_organization_is_not_redirected_and_stays_denied(): void
    {
        $organization = Organization::factory()->trialExpired()->create();
        $user = $this->apiUser($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'owner_id' => $user->id, 'name' => 'Zebrafish']);

        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->postJson('/api/v1/projects', ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/search?q=Zebrafish')
            ->assertOk()
            ->assertExactJson(['query' => 'Zebrafish', 'data' => ['projects' => [], 'tickets' => [], 'comments' => []], 'meta' => ['projects_count' => 0, 'tickets_count' => 0, 'comments_count' => 0]]);
    }

    public function test_the_same_api_calls_work_for_an_organization_still_in_trial(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $user = $this->apiUser($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'owner_id' => $user->id, 'name' => 'Zebrafish']);

        $this->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->getJson('/api/v1/search?q=Zebrafish')->assertOk()->assertJsonPath('meta.projects_count', 1);
    }
}

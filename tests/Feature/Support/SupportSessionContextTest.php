<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\OrganizationSupportSession;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupportSessionContextTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    /**
     * abort_unless(..., 403) throws Symfony's HttpException directly when
     * called outside an HTTP request cycle (which is exactly how a plain
     * PHPUnit test calls a static method) — this asserts on getStatusCode()
     * rather than getCode(), since HttpException's constructor never
     * stores the HTTP status in the exception code.
     */
    private function assertAuthorizeActionDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected authorizeAction() to abort with a 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_start_creates_a_session_expiring_about_an_hour_from_now_and_current_returns_it(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($admin, $organization, 'Debugging a ticket for the customer');

        $this->assertTrue($session->organization->is($organization));
        $this->assertSame('Debugging a ticket for the customer', $session->reason);
        $this->assertNull($session->ended_at);
        $this->assertTrue($session->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));

        $current = SupportSessionContext::current($admin);
        $this->assertNotNull($current);
        $this->assertTrue($current->is($session));
    }

    public function test_current_is_null_for_a_non_super_admin_even_with_a_valid_session_key(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = SupportSessionContext::start($admin, $organization, 'Support request');

        // Force the same session key another (non-Super-Admin) user would see.
        session(['support_session_id' => $session->id]);

        $regularUser = User::factory()->create();

        $this->assertNull(SupportSessionContext::current($regularUser));
    }

    public function test_current_is_null_once_expired_even_though_ended_at_is_still_null(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        SupportSessionContext::start($admin, $organization, 'Support request');

        $this->travel(61)->minutes();

        $this->assertNull(SupportSessionContext::current($admin));
    }

    public function test_stop_ends_the_session_and_current_returns_null_afterward(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = SupportSessionContext::start($admin, $organization, 'Support request');

        SupportSessionContext::stop($admin);

        $this->assertNull(SupportSessionContext::current($admin));
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_a_session_started_by_one_super_admin_is_invisible_to_another_even_with_the_same_session_key(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($adminA, $organization, 'Support request');

        // Force adminB's session to point at adminA's session row.
        session(['support_session_id' => $session->id]);

        $this->assertNull(SupportSessionContext::current($adminB));
    }

    public function test_starting_a_second_session_ends_the_first(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $first = SupportSessionContext::start($admin, $organizationA, 'First reason');
        $second = SupportSessionContext::start($admin, $organizationB, 'Second reason');

        $this->assertNotNull($first->fresh()->ended_at);
        $this->assertNull($second->fresh()->ended_at);

        $current = SupportSessionContext::current($admin);
        $this->assertTrue($current->is($second));
    }

    /**
     * Phase 1 of Support Action: existing callers that never pass a level
     * (every call site before this phase) must keep getting exactly
     * today's behavior — a Read Only session, nothing more.
     */
    public function test_start_defaults_to_the_read_only_level_when_none_is_given(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($admin, $organization, 'Support request');

        $this->assertSame(OrganizationSupportSession::LEVEL_READ_ONLY, $session->level);
        $this->assertFalse($session->isSupportAction());
    }

    public function test_start_accepts_an_explicit_read_only_level(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_READ_ONLY
        );

        $this->assertSame(OrganizationSupportSession::LEVEL_READ_ONLY, $session->level);
    }

    public function test_start_accepts_the_support_action_level(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->assertSame(OrganizationSupportSession::LEVEL_SUPPORT_ACTION, $session->level);
        $this->assertTrue($session->isSupportAction());
        $this->assertSame(
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION,
            $session->fresh()->level,
            'level must be persisted to the database, not just held on the in-memory instance'
        );
    }

    /**
     * A level outside OrganizationSupportSession::LEVELS is never trusted
     * as-is — it falls back to the safe (lower-privilege) default instead
     * of throwing, since Filament form data is one step removed from the
     * browser and this app's convention is to re-validate client-supplied
     * values rather than trust them past the UI layer.
     */
    public function test_an_unrecognized_level_falls_back_to_read_only(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($admin, $organization, 'Support request', 'owner');

        $this->assertSame(OrganizationSupportSession::LEVEL_READ_ONLY, $session->level);
    }

    public function test_current_returns_the_sessions_level(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $current = SupportSessionContext::current($admin);

        $this->assertNotNull($current);
        $this->assertTrue($current->isSupportAction());
    }

    public function test_a_support_action_session_is_no_longer_current_once_expired(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        $this->assertNull(SupportSessionContext::current($admin));
    }

    public function test_a_support_action_session_is_no_longer_current_once_ended(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        SupportSessionContext::stop($admin);

        $this->assertNull(SupportSessionContext::current($admin));
    }

    // -------------------------------------------------------------------
    // Phase 3 of Support Action: authorizeAction()
    // -------------------------------------------------------------------

    public function test_authorize_action_allows_a_super_admin_with_an_active_support_action_session_for_the_target_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $session = SupportSessionContext::authorizeAction($admin, $organization->id);

        $this->assertTrue($session->organization->is($organization));
        $this->assertTrue($session->isSupportAction());
    }

    public function test_authorize_action_denies_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        // Default level, deliberately not passed explicitly — Read Only.
        SupportSessionContext::start($admin, $organization, 'Support request');

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($admin, $organization->id)
        );
    }

    public function test_authorize_action_denies_a_non_super_admin(): void
    {
        $notAnAdmin = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($notAnAdmin, $organization->id)
        );
    }

    public function test_authorize_action_denies_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($admin, $organization->id)
        );
    }

    public function test_authorize_action_denies_an_expired_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->travel(61)->minutes();

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($admin, $organization->id)
        );
    }

    public function test_authorize_action_denies_an_ended_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        SupportSessionContext::stop($admin);

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($admin, $organization->id)
        );
    }

    /**
     * The session is genuinely active and at the right level — the only
     * thing wrong is that the resource being written to belongs to a
     * different organization than the one this session was started for.
     */
    public function test_authorize_action_denies_a_session_for_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organizationA,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($admin, $organizationB->id)
        );
    }

    /**
     * Mirrors test_a_session_started_by_one_super_admin_is_invisible_to_another_even_with_the_same_session_key
     * above — the session-stored id is forced onto a different admin's
     * request, and authorizeAction() must refuse exactly like current()
     * already does for a plain read.
     */
    public function test_authorize_action_denies_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start(
            $adminA,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        session(['support_session_id' => $session->id]);

        $this->assertAuthorizeActionDenied(
            fn () => SupportSessionContext::authorizeAction($adminB, $organization->id)
        );
    }
}

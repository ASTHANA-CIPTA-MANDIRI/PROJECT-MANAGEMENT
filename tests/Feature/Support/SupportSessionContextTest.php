<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\OrganizationSupportAccessGrant;
use App\Models\OrganizationSupportSession;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    private function assertAborts(callable $callback, int $expectedStatus): void
    {
        try {
            $callback();
            $this->fail("Expected an abort with status {$expectedStatus}.");
        } catch (HttpException $exception) {
            $this->assertSame($expectedStatus, $exception->getStatusCode());
        }
    }

    private function owner(Organization $organization): User
    {
        $owner = User::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        return $owner;
    }

    private function admin(Organization $organization): User
    {
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => 'admin']);

        return $admin;
    }

    private function member(Organization $organization): User
    {
        $member = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => 'member']);

        return $member;
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

    // -------------------------------------------------------------------
    // Phase 3 of Support Full Access: the grant lifecycle
    // -------------------------------------------------------------------

    /**
     * The security-critical guard: LEVEL_FULL_ACCESS became a real member
     * of OrganizationSupportSession::LEVELS in this phase, but start() —
     * the same generic entry point PlatformOrganizations::startSupportSession
     * calls for read_only/support_action — must never grant it directly,
     * since it has no way to verify a grant exists.
     */
    public function test_start_never_grants_full_access_directly_even_though_it_is_a_recognized_level(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_FULL_ACCESS
        );

        $this->assertSame(OrganizationSupportSession::LEVEL_READ_ONLY, $session->level);
        $this->assertFalse($session->isFullAccess());
    }

    public function test_request_full_access_creates_a_grant_awaiting_owner_approval(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Need to fix broken data');

        $this->assertTrue($grant->organization->is($organization));
        $this->assertTrue($grant->requester->is($admin));
        $this->assertSame('Need to fix broken data', $grant->reason);
        $this->assertTrue($grant->isRequested());
        $this->assertNull($grant->approved_at);
        $this->assertNull($grant->consumed_at);
        $this->assertNull($grant->revoked_at);
    }

    public function test_request_full_access_is_denied_for_a_non_super_admin(): void
    {
        $notAnAdmin = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->assertAborts(
            fn () => SupportSessionContext::requestFullAccess($notAnAdmin, $organization, 'Reason'),
            403
        );
    }

    public function test_request_full_access_is_denied_when_the_organization_already_has_a_requested_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::requestFullAccess($admin, $organization, 'First request');

        $this->assertAborts(
            fn () => SupportSessionContext::requestFullAccess($admin, $organization, 'Second request'),
            409
        );
    }

    public function test_request_full_access_is_denied_when_the_organization_already_has_an_approved_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'First request');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::requestFullAccess($admin, $organization, 'Second request'),
            409
        );
    }

    public function test_request_full_access_is_allowed_again_once_the_previous_grant_expired(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::requestFullAccess($admin, $organization, 'First request');

        $this->travel(25)->hours();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Second request');

        $this->assertSame('Second request', $grant->reason);
    }

    public function test_request_full_access_is_allowed_again_once_the_previous_grant_was_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $first = SupportSessionContext::requestFullAccess($admin, $organization, 'First request');
        SupportSessionContext::revokeFullAccessGrant($admin, $first);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Second request');

        $this->assertSame('Second request', $grant->reason);
    }

    public function test_approve_full_access_grant_succeeds_for_the_organizations_owner(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $approved = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->assertTrue($approved->approver->is($owner));
        $this->assertTrue($approved->isApproved());
        $this->assertNotNull($approved->grant_expires_at);
    }

    public function test_approve_full_access_grant_is_denied_for_an_admin(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $orgAdmin = $this->admin($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($orgAdmin, $grant),
            403
        );
    }

    public function test_approve_full_access_grant_is_denied_for_a_member(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $orgMember = $this->member($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($orgMember, $grant),
            403
        );
    }

    public function test_approve_full_access_grant_is_denied_for_an_owner_of_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $unrelatedOwner = $this->owner($otherOrganization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($unrelatedOwner, $grant),
            403
        );
    }

    /**
     * Defense in depth per the architecture design's explicit instruction:
     * even though a Super Admin cannot structurally also be an
     * Organization's Owner in any normal flow, self-approval must still be
     * refused outright if it is ever possible.
     */
    public function test_approve_full_access_grant_is_denied_when_the_requester_and_owner_are_the_same_user(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $organization->users()->attach($admin->id, ['role' => 'owner']);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($admin, $grant),
            403
        );
    }

    public function test_approve_full_access_grant_is_denied_for_an_expired_request(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->travel(25)->hours();

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($owner, $grant),
            409
        );
    }

    public function test_approve_full_access_grant_is_denied_for_an_already_approved_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($owner, $grant),
            409
        );
    }

    public function test_approve_full_access_grant_is_denied_for_a_revoked_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($owner, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::approveFullAccessGrant($owner, $grant),
            409
        );
    }

    /**
     * "Fake/nonexistent grant" from the outside — the grant row no longer
     * exists in the database (e.g. a stale/forged id), but the caller
     * still holds an in-memory model instance. firstOrFail() inside the
     * transaction is what actually enforces this — Eloquent's own
     * ModelNotFoundException, not a 403/409 abort_unless(), since there is
     * no row left to check ownership/state against at all.
     */
    public function test_approve_full_access_grant_is_denied_for_a_nonexistent_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant->delete();

        $this->expectException(ModelNotFoundException::class);

        SupportSessionContext::approveFullAccessGrant($owner, $grant);
    }

    public function test_consume_full_access_grant_creates_a_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $session = SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->assertTrue($session->isFullAccess());
        $this->assertTrue($session->organization->is($organization));
        $this->assertTrue($session->accessGrant->is($grant));
        $this->assertNotNull($grant->fresh()->consumed_at);

        $current = SupportSessionContext::current($admin);
        $this->assertNotNull($current);
        $this->assertTrue($current->is($session));
    }

    public function test_consume_full_access_grant_is_denied_for_a_different_super_admin(): void
    {
        $requester = $this->superAdmin();
        $otherAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($requester, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::consumeFullAccessGrant($otherAdmin, $grant),
            403
        );
    }

    public function test_consume_full_access_grant_is_denied_when_not_yet_approved(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::consumeFullAccessGrant($admin, $grant),
            409
        );
    }

    public function test_consume_full_access_grant_is_denied_for_an_expired_grant_window(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->travel(31)->minutes();

        $this->assertAborts(
            fn () => SupportSessionContext::consumeFullAccessGrant($admin, $grant),
            409
        );
    }

    public function test_consume_full_access_grant_is_denied_for_a_revoked_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        SupportSessionContext::revokeFullAccessGrant($owner, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::consumeFullAccessGrant($admin, $grant->fresh()),
            409
        );
    }

    /**
     * The anti-replay / double-consume guard: a second consume against a
     * grant already consumed once must fail, not silently create a second
     * session.
     */
    public function test_consume_full_access_grant_is_denied_when_already_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::consumeFullAccessGrant($admin, $grant->fresh()),
            409
        );

        $this->assertSame(
            1,
            OrganizationSupportSession::where('access_grant_id', $grant->id)->count(),
            'A replayed consume must never produce a second session for the same grant.'
        );
    }

    public function test_consume_full_access_grant_is_denied_for_a_nonexistent_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        $grant->delete();

        $this->expectException(ModelNotFoundException::class);

        SupportSessionContext::consumeFullAccessGrant($admin, $grant);
    }

    public function test_consume_full_access_grant_ends_any_existing_session_first(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $owner = $this->owner($organizationB);

        $firstSession = SupportSessionContext::start($admin, $organizationA, 'Unrelated read-only session');

        $grant = SupportSessionContext::requestFullAccess($admin, $organizationB, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        $fullAccessSession = SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->assertNotNull($firstSession->fresh()->ended_at);
        $this->assertNull($fullAccessSession->fresh()->ended_at);
    }

    // -------------------------------------------------------------------
    // Phase 6 security audit: atomicity of Grant consume + Session
    // creation, empirically proven, not assumed from reading the code.
    // -------------------------------------------------------------------

    /**
     * Forces OrganizationSupportSession::create() to fail from inside the
     * same DB::transaction() consumeFullAccessGrant() already wraps both
     * writes in — a test-only Eloquent model event listener, never a
     * production code path or a permanent hook. Proves the transaction
     * covers the *whole* operation: the Grant's own consumed_at write,
     * which happens earlier in the same closure, must roll back too, not
     * just the failed session insert.
     *
     * Registering via OrganizationSupportSession::creating() is safe to
     * leave unflushed at the end of the test in this codebase's test
     * setup: Tests\TestCase uses Laravel's CreatesApplication trait,
     * which rebuilds the entire application (and therefore the model
     * event dispatcher) fresh in setUp() for every test method — a
     * listener registered here cannot leak into another test. It is
     * still flushed explicitly in a finally block for clarity.
     */
    public function test_consume_full_access_grant_rolls_back_the_grant_when_session_creation_fails(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        OrganizationSupportSession::creating(function () {
            throw new \RuntimeException('Forced failure for Phase 6 rollback proof — test-only, never in production code');
        });

        try {
            try {
                SupportSessionContext::consumeFullAccessGrant($admin, $grant);
                $this->fail('Expected the forced session-creation exception to propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Forced failure for Phase 6 rollback proof — test-only, never in production code',
                    $exception->getMessage()
                );
            }
        } finally {
            OrganizationSupportSession::flushEventListeners();
        }

        // The Grant's consumed_at write (which happened before the
        // forced failure, inside the same transaction) must have been
        // rolled back — it is not permanently consumed by a failed
        // attempt.
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertTrue($grant->fresh()->isApproved());

        // No orphan session was left behind either.
        $this->assertSame(0, OrganizationSupportSession::where('access_grant_id', $grant->id)->count());

        // The Grant is still usable afterward — a failed attempt does
        // not permanently burn it.
        $session = SupportSessionContext::consumeFullAccessGrant($admin, $grant->fresh());
        $this->assertTrue($session->isFullAccess());
    }

    /**
     * Full Access is a temporary session, never a standing grant of
     * membership or a role — consuming a Grant must never create an
     * organization_users row for the admin, nor change their Spatie
     * roles in any way.
     */
    public function test_consuming_a_grant_never_creates_organization_membership_or_changes_roles(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $rolesBefore = $admin->roles()->pluck('name')->sort()->values()->all();

        $this->activeFullAccessSession($admin, $organization);

        $this->assertFalse(
            $organization->users()->whereKey($admin->id)->exists(),
            'Consuming a Full Access grant must never create an organization_users row.'
        );

        $this->assertSame(
            $rolesBefore,
            $admin->fresh()->roles()->pluck('name')->sort()->values()->all(),
            "Consuming a Full Access grant must never change the admin's Spatie roles."
        );
    }

    public function test_revoke_full_access_grant_by_owner_before_approval(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($owner, $grant);

        $this->assertTrue($grant->fresh()->isRevoked());
    }

    public function test_revoke_full_access_grant_by_requester_before_approval(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($admin, $grant);

        $this->assertTrue($grant->fresh()->isRevoked());
    }

    public function test_revoke_full_access_grant_is_denied_for_an_unrelated_user(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $unrelatedUser = User::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->assertAborts(
            fn () => SupportSessionContext::revokeFullAccessGrant($unrelatedUser, $grant),
            403
        );
    }

    public function test_revoke_full_access_grant_is_denied_once_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        SupportSessionContext::consumeFullAccessGrant($admin, $grant);

        $this->assertAborts(
            fn () => SupportSessionContext::revokeFullAccessGrant($owner, $grant->fresh()),
            409
        );
    }

    public function test_revoke_full_access_grant_is_denied_for_a_nonexistent_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant->delete();

        $this->expectException(ModelNotFoundException::class);

        SupportSessionContext::revokeFullAccessGrant($admin, $grant);
    }

    public function test_revoke_full_access_grant_is_idempotent_when_already_revoked(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($owner, $grant);

        // A second revoke (e.g. Owner and Super Admin both clicking
        // cancel near-simultaneously) must not throw.
        SupportSessionContext::revokeFullAccessGrant($admin, $grant->fresh());

        $this->assertTrue($grant->fresh()->isRevoked());
    }

    private function activeFullAccessSession(User $admin, Organization $organization): OrganizationSupportSession
    {
        $owner = $this->owner($organization);
        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);

        return SupportSessionContext::consumeFullAccessGrant($admin, $grant);
    }

    public function test_authorize_full_access_allows_a_full_access_session_for_the_target_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        $session = SupportSessionContext::authorizeFullAccess($admin, $organization->id);

        $this->assertTrue($session->isFullAccess());
        $this->assertTrue($session->organization->is($organization));
    }

    public function test_authorize_full_access_denies_a_support_action_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start($admin, $organization, 'Support request');

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_there_is_no_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_a_session_for_a_different_organization(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organizationA);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organizationB->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_the_session_belongs_to_a_different_super_admin(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->activeFullAccessSession($adminA, $organization);

        session(['support_session_id' => $session->id]);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($adminB, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    // -------------------------------------------------------------------
    // Phase 5 security audit: level alone (session.level === full_access)
    // is never sufficient — every test below constructs a session that
    // passes the pre-Phase-5 checks (active, right admin, right level,
    // right organization) but still must be denied because its Grant is
    // missing, broken, inconsistent, or its own lifecycle is invalid.
    // -------------------------------------------------------------------

    public function test_authorize_full_access_denies_an_ended_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        SupportSessionContext::stop($admin);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_a_plain_non_super_admin_actor(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->activeFullAccessSession($admin, $organization);

        session(['support_session_id' => $session->id]);
        $notAnAdmin = User::factory()->create();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($notAnAdmin, $organization->id),
            403
        );
    }

    /**
     * Directly constructs a session row the same way a raw database
     * tampering (or a future bug that skips consumeFullAccessGrant())
     * would — never through SupportSessionContext's own public API, which
     * never produces this shape on its own.
     */
    private function forceFullAccessSession(User $admin, Organization $organization, ?int $accessGrantId): OrganizationSupportSession
    {
        $session = OrganizationSupportSession::create([
            'organization_id' => $organization->id,
            'super_admin_id' => $admin->id,
            'access_grant_id' => $accessGrantId,
            'reason' => 'Forced session for a Phase 5 defense-in-depth test',
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        session(['support_session_id' => $session->id]);

        return $session;
    }

    public function test_authorize_full_access_denies_a_full_access_session_with_no_access_grant_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->forceFullAccessSession($admin, $organization, null);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_a_full_access_session_with_a_nonexistent_grant_id(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->forceFullAccessSession($admin, $organization, 999999);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_the_grant_is_still_requested(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $this->forceFullAccessSession($admin, $organization, $grant->id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_the_grant_is_approved_but_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        $this->forceFullAccessSession($admin, $organization, $grant->id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_the_grant_expired_before_being_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->travel(25)->hours();

        $this->forceFullAccessSession($admin, $organization, $grant->id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    public function test_authorize_full_access_denies_when_the_grant_was_revoked_before_being_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::revokeFullAccessGrant($admin, $grant);
        $this->forceFullAccessSession($admin, $organization, $grant->id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    /**
     * Defense in depth: revokeFullAccessGrant() itself already refuses to
     * revoke a consumed grant (abort_if($grant->consumed_at !== null,
     * 409)), so this state is unreachable through the app's own API. This
     * proves authorizeFullAccess() does not rely on that guard holding
     * elsewhere — it re-checks revoked_at directly, even for a grant that
     * legitimately passed isConsumed().
     */
    public function test_authorize_full_access_denies_when_a_consumed_grants_revoked_at_is_set_afterward(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    /**
     * Simulated data corruption / future bug, not a reachable app state:
     * proves authorizeFullAccess() re-derives isConsumed() from the Grant
     * row itself on every call rather than trusting that a session
     * currently at LEVEL_FULL_ACCESS implies its Grant is still consumed.
     */
    public function test_authorize_full_access_denies_when_a_consumed_grants_consumed_at_is_cleared_afterward(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update(['consumed_at' => null]);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    /**
     * Organization isolation matrix (Phase 5 security audit, Bagian 10),
     * first combination: Session -> Organization A, Grant -> Organization
     * B, Target -> Organization A. The session's own organization_id
     * matches the target, so only the independent
     * grant->organization_id === session->organization_id check below
     * catches this — proving a single ID comparison is not enough.
     */
    public function test_organization_isolation_denies_when_the_grant_belongs_to_a_different_organization_than_the_session(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        // A real, validly-consumed grant, but for Organization B.
        $sessionB = $this->activeFullAccessSession($admin, $organizationB);
        $grantForB = $sessionB->access_grant_id;

        // A session for Organization A, forged to reference Organization
        // B's grant — the shape a stale/forged access_grant_id would take.
        $this->forceFullAccessSession($admin, $organizationA, $grantForB);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organizationA->id),
            403
        );
    }

    /**
     * Organization isolation matrix, second combination: Session ->
     * Organization A, Grant -> Organization A, Target -> Organization B.
     * Caught by the pre-existing session->organization_id === target
     * check, kept here as an explicit, separately-named regression for
     * this exact matrix combination.
     */
    public function test_organization_isolation_denies_when_the_target_differs_from_a_matching_session_and_grant(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organizationA);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organizationB->id),
            403
        );
    }

    /**
     * Organization isolation matrix, third combination: Session ->
     * Organization A, Grant -> Organization B, Target -> Organization B.
     */
    public function test_organization_isolation_denies_when_the_target_and_grant_both_differ_from_the_session(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $sessionB = $this->activeFullAccessSession($admin, $organizationB);
        $this->forceFullAccessSession($admin, $organizationA, $sessionB->access_grant_id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organizationB->id),
            403
        );
    }

    /**
     * The umbrella case this entire Phase 5 pass exists to close: a
     * session whose `level` column was flipped straight to full_access
     * (e.g. raw DB tampering, or a future code path that forgets to go
     * through consumeFullAccessGrant()) but which never went through the
     * Grant flow at all — access_grant_id is null, same as any ordinary
     * read_only/support_action session.
     */
    public function test_authorize_full_access_denies_a_session_whose_level_was_flipped_directly_without_a_grant(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($admin, $organization, 'Ordinary read-only session');
        OrganizationSupportSession::whereKey($session->id)->update([
            'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
        ]);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeFullAccess($admin, $organization->id),
            403
        );
    }

    /**
     * FULL_ACCESS_CAPABILITIES holds exactly one entry as of Phase 9
     * ('change_ticket_status', tested separately below) — every other
     * candidate string, including plausible-looking ones and destructive
     * ones from the Phase 8 design matrix, must still be denied.
     */
    public function test_full_access_capability_allowlist_denies_every_capability_because_none_is_approved_yet(): void
    {
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('ticket.update_content'));
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('ticket.delete'));
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('project.description.update'));
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('organization.member.remove'));
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed(''));
    }

    // -------------------------------------------------------------------
    // Phase 9: authorizeCapability() — the superset relationship between
    // Full Access and Support Action, made real. change_ticket_status is
    // the sole pilot capability; see SupportSessionContext's own docblock
    // on authorizeCapability()/FULL_ACCESS_CAPABILITIES for the full
    // case-by-case trace this test class exercises below.
    // -------------------------------------------------------------------

    /**
     * change_ticket_status is the one capability Phase 9 approved — a
     * valid, consumed, un-revoked Full Access grant for the target
     * organization must be allowed through, proving Full Access is a real
     * superset of Support Action for this specific capability.
     */
    public function test_authorize_capability_allows_a_full_access_session_for_the_allowlisted_capability(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        $session = SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status');

        $this->assertTrue($session->isFullAccess());
        $this->assertTrue($session->organization->is($organization));
    }

    /**
     * Explicit proof that Full Access is not a wildcard: the exact same
     * valid/consumed/un-revoked Full Access session used in the positive
     * test above must still be denied for a capability that is not in
     * FULL_ACCESS_CAPABILITIES — abort_unless(isFullAccessCapabilityAllowed())
     * must fire before authorizeFullAccess() is ever reached.
     */
    public function test_authorize_capability_denies_a_full_access_session_for_a_capability_not_in_the_allowlist(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_priority'),
            403
        );
    }

    /**
     * A Support Action session must keep behaving exactly as
     * authorizeAction() already does — allowed for ANY capability string,
     * including one that is not (and never will be) in
     * FULL_ACCESS_CAPABILITIES, because the first branch of
     * authorizeCapability() returns before the allowlist is even
     * consulted.
     */
    public function test_authorize_capability_allows_a_support_action_session_for_any_capability(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );

        $session = SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_priority');

        $this->assertTrue($session->isSupportAction());
    }

    /**
     * Full Access with no grant at all — never requested/approved/consumed
     * through the app's own API. Constructed the same way
     * forceFullAccessSession() already does for authorizeFullAccess()'s own
     * Phase 5 defense-in-depth tests: the shape raw DB tampering (or a
     * future bug skipping consumeFullAccessGrant()) would take.
     */
    public function test_authorize_capability_denies_a_full_access_session_with_no_grant_at_all(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->forceFullAccessSession($admin, $organization, null);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    public function test_authorize_capability_denies_a_full_access_session_whose_grant_is_approved_but_not_yet_consumed(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        $grant = SupportSessionContext::approveFullAccessGrant($owner, $grant);
        $this->forceFullAccessSession($admin, $organization, $grant->id);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    public function test_authorize_capability_denies_an_expired_full_access_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $this->activeFullAccessSession($admin, $organization);

        $this->travel(61)->minutes();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    /**
     * Mirrors test_authorize_full_access_denies_when_a_consumed_grants_revoked_at_is_set_afterward
     * above — a Grant that was legitimately consumed but is later revoked
     * (e.g. an Owner revoking mid-session) must still deny, even though the
     * session row itself never changes.
     */
    public function test_authorize_capability_denies_when_the_consumed_grant_is_revoked_afterward(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->activeFullAccessSession($admin, $organization);

        OrganizationSupportAccessGrant::whereKey($session->access_grant_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    public function test_authorize_capability_denies_a_non_super_admin(): void
    {
        $notAnAdmin = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($notAnAdmin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    public function test_authorize_capability_denies_when_there_is_no_session_at_all(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    public function test_authorize_capability_denies_a_read_only_session(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::start($admin, $organization, 'Support request');

        $this->assertAborts(
            fn () => SupportSessionContext::authorizeCapability($admin, $organization->id, 'change_ticket_status'),
            403
        );
    }

    /**
     * The structural proof (Phase 9 test requirement #9): the
     * clearly-destructive capabilities from the Phase 8 design matrix that
     * remain unapproved must stay false, without needing to actually
     * exercise a destructive mutation to prove it.
     *
     * 'delete_ticket'/'restore_ticket' were part of this list under Phase 9
     * — Phase 11 explicitly approved exactly those two (Destructive
     * Category A, see FULL_ACCESS_CAPABILITIES' own docblock),
     * 'delete_sprint'/'restore_sprint' were likewise part of this list until
     * Phase 12 explicitly approved them (Destructive Category B), and
     * 'delete_project'/'restore_project' stayed here until Phase 13
     * explicitly approved them (Destructive Category C) — all three pairs'
     * now-true assertions live in
     * test_full_access_capability_allowlist_allows_change_ticket_status_and_the_phase_11_12_and_13_capabilities
     * below. bulk_delete/force_delete were never part of any phase's scope
     * and remain denied here.
     */
    public function test_full_access_capability_allowlist_denies_every_destructive_capability(): void
    {
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_delete'));
        $this->assertFalse(SupportSessionContext::isFullAccessCapabilityAllowed('force_delete'));
    }

    /**
     * Renamed from the Phase 9
     * test_full_access_capability_allowlist_allows_only_change_ticket_status
     * — "only" stopped being accurate once Phase 11 added
     * 'delete_ticket'/'restore_ticket', again once Phase 12 added
     * 'delete_sprint'/'restore_sprint', again once Phase 13 added
     * 'delete_project'/'restore_project', and again once Phase 14 added
     * 'bulk_delete_ticket'/'bulk_restore_ticket'/'bulk_delete_sprint'/
     * 'bulk_restore_sprint', to FULL_ACCESS_CAPABILITIES. Deliberately does
     * not assert every other capability is false
     * (test_full_access_capability_allowlist_denies_every_capability_because_none_is_approved_yet
     * and test_full_access_capability_allowlist_denies_every_destructive_capability
     * already cover that from the other direction — including that
     * 'bulk_delete' itself, a different literal string from 'bulk_delete_ticket'/
     * 'bulk_delete_sprint', stays denied).
     */
    public function test_full_access_capability_allowlist_allows_change_ticket_status_and_the_phase_11_12_13_and_14_capabilities(): void
    {
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('change_ticket_status'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_ticket'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_ticket'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_sprint'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_sprint'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('delete_project'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('restore_project'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_delete_ticket'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_restore_ticket'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_delete_sprint'));
        $this->assertTrue(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_restore_sprint'));
    }
}

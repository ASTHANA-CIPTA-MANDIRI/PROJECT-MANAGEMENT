<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Filament\Pages\PlatformOrganizations;
use App\Models\Organization;
use App\Models\OrganizationSupportAccessGrant;
use App\Models\OrganizationSupportSession;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7 — Full Access UI/UX Gate, Super Admin side. The domain layer
 * itself (App\Support\SupportSessionContext's requestFullAccess()/
 * approveFullAccessGrant()/consumeFullAccessGrant()/revokeFullAccessGrant())
 * is already exhaustively covered by tests/Feature/Support/SupportSessionContextTest.php
 * (Phase 1-6, untouched here) — this suite only proves the Livewire wiring
 * on top of it: the three new table actions on PlatformOrganizations
 * (requestFullAccess, startFullAccess, cancelFullAccessRequest) call the
 * right method with the right actor/record, and never let a client-supplied
 * grant id substitute for the server-derived one.
 */
class PlatformOrganizationsFullAccessTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::firstOrCreate(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    private function owner(Organization $organization): User
    {
        $owner = User::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        return $owner;
    }

    // ------------------------------------------------- 1: negative regression

    /**
     * LEVEL_FULL_ACCESS can never be reached through SupportSessionContext::
     * start() (see that method's own docblock) — this proves the existing
     * startSupportSession action still only ever produces a read_only or
     * support_action session even if a request tries to force the value,
     * i.e. Full Access was never wired in as a third Radio option here.
     */
    public function test_full_access_cannot_be_forced_through_the_start_support_session_action(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('startSupportSession', $organization, data: [
                'reason' => 'Trying to force full access',
                'level' => OrganizationSupportSession::LEVEL_FULL_ACCESS,
            ])
            ->assertHasNoTableActionErrors();

        $session = OrganizationSupportSession::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(OrganizationSupportSession::LEVEL_READ_ONLY, $session->level);
    }

    // ------------------------------------------------- 2: reason required

    public function test_requesting_full_access_without_a_reason_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('requestFullAccess', $organization, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertFalse(
            OrganizationSupportAccessGrant::where('organization_id', $organization->id)->exists()
        );
    }

    // ------------------------------------------------- 3: super admin only

    public function test_a_non_super_admin_cannot_reach_the_platform_page_or_request_full_access(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();

        $this->assertFalse(
            OrganizationSupportAccessGrant::where('organization_id', $organization->id)->exists()
        );
    }

    // ------------------------------------------------- 5: requester recorded

    public function test_super_admin_can_request_full_access_with_a_reason_and_is_recorded_as_requester(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('requestFullAccess', $organization, data: [
                'reason' => 'Investigating a billing discrepancy the customer reported',
            ])
            ->assertHasNoTableActionErrors();

        $grant = OrganizationSupportAccessGrant::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame($admin->id, $grant->requested_by);
        $this->assertSame('Investigating a billing discrepancy the customer reported', $grant->reason);
        $this->assertSame('requested', $grant->status());
    }

    /**
     * canRequestFullAccess() hides the action once an active (REQUESTED or
     * APPROVED-and-unconsumed) grant already exists for this organization —
     * UX only (requestFullAccess() itself is the real 409 gate under
     * lockForUpdate(), already covered by
     * SupportSessionContextTest::test_request_full_access_is_denied_when_the_organization_already_has_a_requested_grant()),
     * but this proves the button does not even invite a second request
     * that would only fail on submit.
     */
    public function test_requesting_full_access_is_hidden_once_a_request_is_already_active(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::requestFullAccess($admin, $organization, 'First request');

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionHidden('requestFullAccess', $organization);

        $this->assertSame(
            1,
            OrganizationSupportAccessGrant::where('organization_id', $organization->id)->count()
        );
    }

    // ------------------------------------------------- 7/8: status + expiry column

    public function test_the_full_access_status_column_shows_requested_and_expiry_after_a_request(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['name' => 'Needs Full Access Co']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('requestFullAccess', $organization, data: [
                'reason' => 'Need to fix cross-project data',
            ])
            ->assertHasNoTableActionErrors()
            // Both "Requested" and "Expires :date" are translated in
            // lang/id.json (TranslationCompletenessTest requires every
            // __() literal to have an entry) — app.locale defaults to 'id'
            // (config/app.php), so the rendered table shows the Indonesian
            // strings, not the English source literals.
            ->assertSee('Diminta')
            ->assertSee('Berakhir');
    }

    public function test_the_full_access_status_column_shows_approved_once_the_owner_approves(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            // "Approved — ready to start" is translated to "Disetujui —
            // siap dimulai" in lang/id.json — see the note above on
            // app.locale defaulting to 'id'.
            ->assertSee('Disetujui');
    }

    // ------------------------------------------------- start full access

    public function test_super_admin_can_start_full_access_once_approved(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionVisible('startFullAccess', $organization)
            ->callTableAction('startFullAccess', $organization)
            ->assertHasNoTableActionErrors()
            ->assertRedirect(OrganizationSupportView::getUrl());

        $session = OrganizationSupportSession::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(OrganizationSupportSession::LEVEL_FULL_ACCESS, $session->level);
        $this->assertSame($admin->id, $session->super_admin_id);
        $this->assertSame($grant->id, $session->access_grant_id);
    }

    /**
     * Defense in depth (see class docblock on PlatformOrganizations'
     * startFullAccess action): the button itself must not even be shown to
     * a different Super Admin than the one who requested the grant, no
     * client-supplied grant id is ever involved — canStartFullAccess()
     * re-derives the relevant grant from the organization record alone.
     */
    public function test_starting_full_access_is_hidden_for_a_different_super_admin(): void
    {
        $requestingAdmin = $this->superAdmin();
        $otherAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $grant = SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');
        SupportSessionContext::approveFullAccessGrant($owner, $grant);

        $this->actingAs($otherAdmin);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionHidden('startFullAccess', $organization);

        $this->assertFalse(
            OrganizationSupportSession::where('organization_id', $organization->id)->exists()
        );
    }

    public function test_starting_full_access_is_hidden_while_still_only_requested(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionHidden('startFullAccess', $organization);
    }

    // ------------------------------------------------- cancel own request

    public function test_super_admin_can_cancel_their_own_pending_full_access_request(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($admin, $organization, 'Reason');

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('cancelFullAccessRequest', $organization)
            ->assertHasNoTableActionErrors();

        $this->assertSame('revoked', $grant->fresh()->status());
    }

    public function test_cancel_full_access_request_is_hidden_for_a_different_super_admin(): void
    {
        $requestingAdmin = $this->superAdmin();
        $otherAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $grant = SupportSessionContext::requestFullAccess($requestingAdmin, $organization, 'Reason');

        $this->actingAs($otherAdmin);

        Livewire::test(PlatformOrganizations::class)
            ->assertTableActionHidden('cancelFullAccessRequest', $organization);

        $this->assertSame('requested', $grant->fresh()->status());
    }
}

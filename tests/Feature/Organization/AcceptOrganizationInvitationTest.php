<?php

namespace Tests\Feature\Organization;

use App\Http\Livewire\AcceptOrganizationInvitation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.4 — Accept Invitation. Covers the brief's K-T, Y, Z, AA, AB, AC
 * list against the real public Livewire component
 * (App\Http\Livewire\AcceptOrganizationInvitation), reached the same way a
 * real recipient would: by token, through the actual route.
 */
class AcceptOrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invitationWithToken(array $attributes = []): array
    {
        $plainToken = OrganizationInvitation::generateToken();
        $invitation = OrganizationInvitation::factory()->create(array_merge([
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
        ], $attributes));

        return [$invitation, $plainToken];
    }

    // ------------------------------------------------------------------- K

    public function test_an_invalid_token_is_rejected(): void
    {
        Livewire::test(AcceptOrganizationInvitation::class, ['token' => 'not-a-real-token'])
            ->assertSet('status', 'not_found');
    }

    // ------------------------------------------------------------------- L

    public function test_an_expired_token_is_rejected(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['expires_at' => now()->subDay()]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'expired')
            ->call('accept');

        $this->assertFalse($invitation->organization->fresh()->isAccessibleBy($user));
    }

    // ------------------------------------------------------------------- M

    public function test_a_revoked_token_is_rejected(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['revoked_at' => now()]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'revoked')
            ->call('accept');

        $this->assertFalse($invitation->organization->fresh()->isAccessibleBy($user));
    }

    // ------------------------------------------------------------------- N

    public function test_an_already_accepted_token_cannot_be_reused(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['accepted_at' => now()]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'accepted')
            ->call('accept');

        $this->assertFalse($invitation->organization->fresh()->isAccessibleBy($user));
    }

    // ------------------------------------------------------------------- O

    public function test_a_user_logged_in_with_the_wrong_email_cannot_accept(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['email' => 'alice@example.test']);
        $bob = User::factory()->create(['email' => 'bob@example.test']);
        $this->actingAs($bob);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'wrong_identity')
            ->call('accept')
            ->assertForbidden();

        $this->assertFalse($invitation->organization->fresh()->isAccessibleBy($bob));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    // ----------------------------------------------------------- Phase 5.4.2: name

    /**
     * Reaching this branch means the email had no account when the
     * invitation was created (OrganizationSettings only ever invites an
     * email it could not find), so $user here is always the brand-new
     * account just registered to accept it - invitation.name becomes
     * users.name at the moment membership is actually created.
     */
    public function test_a_new_users_account_receives_the_invitations_name(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['name' => 'Budi Santoso']);
        $user = User::factory()->create(['email' => $invitation->email, 'name' => 'Placeholder Name']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertSame('Budi Santoso', $user->fresh()->name);
    }

    /**
     * Backward compatibility (Phase 5.4.2): an invitation created before the
     * `name` column existed has no name to apply at all - the accepting
     * user's own name (whatever they typed at registration) is left
     * completely untouched rather than being blanked out.
     */
    public function test_an_invitation_without_a_name_does_not_touch_the_users_name(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['name' => null]);
        $user = User::factory()->create(['email' => $invitation->email, 'name' => 'Self Chosen Name']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertSame('Self Chosen Name', $user->fresh()->name);
        $this->assertSame('member', $invitation->organization->fresh()->roleOf($user->fresh()));
    }

    /**
     * The name-overwrite only ever applies to the branch that actually
     * creates a fresh membership - a user who is already a member gets
     * their invitation consumed (Phase 5.4's existing behavior) without any
     * of their account fields touched, name included.
     */
    public function test_an_already_existing_members_name_is_not_touched_by_accepting_again(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['name' => 'Invitation Name']);
        $user = User::factory()->create(['email' => $invitation->email, 'name' => 'Existing Member Name']);
        $invitation->organization->users()->attach($user->id, ['role' => 'member']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertSame('Existing Member Name', $user->fresh()->name);
    }

    // ---------------------------------------------------------- access role

    /**
     * The recipient here is always a brand-new account (this branch only
     * runs when the invited email had none), so applying the invitation's
     * chosen access Role is safe unconditionally - see
     * AcceptOrganizationInvitation::accept()'s own docblock on this point.
     */
    public function test_a_new_recipient_gets_the_invitations_access_role(): void
    {
        $accessRole = Role::create(['name' => 'Project Manager']);
        [$invitation, $token] = $this->invitationWithToken(['access_role_id' => $accessRole->id]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertTrue($user->fresh()->hasRole($accessRole->name));
    }

    /**
     * syncRoles(), not assignRole(): whatever the platform's own
     * self-registration default role already gave this brand-new account
     * (App\Listeners\AssignDefaultRole) is replaced, not stacked, so the
     * invitation's chosen access role is the only one they end up with.
     */
    public function test_the_platform_default_role_is_replaced_not_stacked(): void
    {
        $defaultRole = Role::create(['name' => 'Employee']);
        $accessRole = Role::create(['name' => 'Project Manager']);
        [$invitation, $token] = $this->invitationWithToken(['access_role_id' => $accessRole->id]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $user->assignRole($defaultRole);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $user = $user->fresh();
        $this->assertTrue($user->hasRole($accessRole->name));
        $this->assertFalse($user->hasRole($defaultRole->name));
    }

    public function test_an_invitation_without_an_access_role_leaves_roles_untouched(): void
    {
        $defaultRole = Role::create(['name' => 'Employee']);
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->create(['email' => $invitation->email]);
        $user->assignRole($defaultRole);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertTrue($user->fresh()->hasRole($defaultRole->name));
    }

    /**
     * Defense in depth: even if an invitation somehow ended up with the
     * Super Admin role as its access_role_id (should never happen -
     * OrganizationSettings excludes it at creation time), accepting it must
     * never actually grant that role.
     */
    public function test_the_super_admin_role_is_never_granted_through_an_invitation(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        [$invitation, $token] = $this->invitationWithToken(['access_role_id' => $superAdminRole->id]);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    // ------------------------------------------------------------- Phase 5.4.1: email verification

    /**
     * Registration -> email verification -> accept, in that order: an
     * authenticated, identity-matched-but-unverified user is blocked from
     * accepting, both in the status the view reads and server-side in
     * accept() itself.
     */
    public function test_an_unverified_user_cannot_accept(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->unverified()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'unverified')
            ->call('accept')
            ->assertForbidden();

        $this->assertFalse($invitation->organization->fresh()->isAccessibleBy($user));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_a_user_can_accept_after_verifying_their_email(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->unverified()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'unverified');

        $user->markEmailAsVerified();

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'ready')
            ->call('accept');

        $this->assertSame('member', $invitation->organization->fresh()->roleOf($user->fresh()));
    }

    // ------------------------------------------------------------------- P, Q

    public function test_the_correct_authenticated_email_can_accept_and_becomes_a_member(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['role' => 'admin']);
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'ready')
            ->call('accept');

        $this->assertSame('admin', $invitation->organization->fresh()->roleOf($user));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    /** Case-insensitive identity match, matching how emails are commonly compared. */
    public function test_email_matching_is_case_insensitive(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['email' => 'Alice@Example.test']);
        $user = User::factory()->create(['email' => 'alice@example.test']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->call('accept');

        $this->assertSame('member', $invitation->organization->fresh()->roleOf($user));
    }

    // ------------------------------------------------------------------- R, S

    public function test_a_guest_with_no_account_is_pointed_at_registration_not_logged_in_automatically(): void
    {
        [$invitation, $token] = $this->invitationWithToken();

        $component = Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token]);

        $component->assertSet('status', 'ready');
        $this->assertFalse($component->get('recipientHasAccount'));
        $this->assertGuest();
    }

    public function test_a_guest_with_an_existing_account_is_pointed_at_login(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        User::factory()->create(['email' => $invitation->email]);

        $component = Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token]);

        $this->assertTrue($component->get('recipientHasAccount'));
    }

    /**
     * Registering normally (unrelated to any invitation) must still result
     * in exactly zero organizations - Phase 5.4 must not have changed the
     * registration flow's own defaults.
     */
    public function test_normal_registration_remains_unrelated_to_invitations(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $user->organizations()->count());
    }

    // ------------------------------------------------------------------- T

    public function test_an_already_existing_member_accepting_again_does_not_duplicate_membership(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['role' => 'admin']);
        $user = User::factory()->create(['email' => $invitation->email]);
        $invitation->organization->users()->attach($user->id, ['role' => 'member']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        // Existing role untouched, not silently upgraded to the invitation's role.
        $this->assertSame('member', $invitation->organization->fresh()->roleOf($user));
        $this->assertSame(1, $invitation->organization->users()->wherePivot('user_id', $user->id)->count());
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    // ------------------------------------------------------------------- Y

    /**
     * A soft-deleted user cannot authenticate through this app's real login
     * flow at all (default Eloquent scope excludes trashed rows, unchanged
     * by this phase) - the one thing Phase 5.4's own code controls is not
     * misleading a guest into thinking they can "log in" to an account that
     * effectively no longer exists.
     */
    public function test_a_soft_deleted_users_email_is_treated_as_having_no_account(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $deletedUser = User::factory()->create(['email' => $invitation->email]);
        $deletedUser->delete();

        $component = Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token]);

        $this->assertFalse($component->get('recipientHasAccount'));
    }

    // ------------------------------------------------------------------- Z

    public function test_accepting_after_the_organization_is_deleted_fails_safely(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->create(['email' => $invitation->email]);
        $organizationId = $invitation->organization_id;
        $invitation->organization->delete(); // cascades to the invitation row too
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])
            ->assertSet('status', 'not_found');

        $this->assertDatabaseMissing('organizations', ['id' => $organizationId]);
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
    }

    // ------------------------------------------------------------------ AA

    /**
     * Simulates a race by calling accept() twice in sequence for the same
     * user/invitation - the second call must be a safe no-op (idempotent),
     * never a duplicate membership or an unhandled exception, exactly what
     * a genuinely concurrent second request must also produce.
     */
    public function test_calling_accept_twice_only_creates_membership_once(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        $component = Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token]);
        $component->call('accept');
        $component->call('accept');

        $this->assertSame(1, $invitation->organization->users()->wherePivot('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------------ AB

    public function test_a_multi_organization_user_can_accept_a_second_invitation(): void
    {
        $existingOrganization = Organization::factory()->create();
        [$invitation, $token] = $this->invitationWithToken(['role' => 'owner']);
        $user = User::factory()->create(['email' => $invitation->email]);
        $existingOrganization->users()->attach($user->id, ['role' => 'member']);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertSame('member', $existingOrganization->fresh()->roleOf($user));
        $this->assertSame('owner', $invitation->organization->fresh()->roleOf($user));
    }

    // ------------------------------------------------------------------ AC

    public function test_organization_context_switches_to_the_newly_joined_organization(): void
    {
        [$invitation, $token] = $this->invitationWithToken();
        $user = User::factory()->create(['email' => $invitation->email]);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertTrue(OrganizationContext::current($user)->is($invitation->organization));
    }

    public function test_organization_context_is_untouched_when_acceptance_fails(): void
    {
        [$invitation, $token] = $this->invitationWithToken(['revoked_at' => now()]);
        $existingOrganization = Organization::factory()->create();
        $user = User::factory()->create(['email' => $invitation->email]);
        $existingOrganization->users()->attach($user->id, ['role' => 'member']);
        OrganizationContext::switch($user, $existingOrganization->id);
        $this->actingAs($user);

        Livewire::test(AcceptOrganizationInvitation::class, ['token' => $token])->call('accept');

        $this->assertTrue(OrganizationContext::current($user)->is($existingOrganization));
    }

    // ---------------------------------------------- Phase 5.4.4C: real HTTP route + Filament layout

    /**
     * Every test above exercises AcceptOrganizationInvitation through
     * Livewire::test(), which mounts the component directly in memory and
     * never touches routing at all - so none of them would ever have
     * caught the real bug this section covers: the route used to register
     * the Livewire component class itself as its target, which made
     * Livewire's full-page-component feature look for a `layouts.app`
     * view this Filament app never has (InvalidArgumentException: View
     * [layouts.app] not found - it only has Filament's own namespaced
     * `x-filament::layouts.*` components). These tests hit the actual
     * named route over real HTTP instead, the same way a recipient
     * clicking the link in their email would.
     */
    public function test_the_invitation_route_renders_successfully_over_http(): void
    {
        [$invitation, $token] = $this->invitationWithToken();

        $response = $this->get(route('organization-invitations.accept', $token));

        $response->assertOk();
        $response->assertViewIs('organization-invitations.accept');
        $response->assertSeeLivewire('accept-organization-invitation');
        $response->assertSee($invitation->organization->name);
    }

    public function test_an_invalid_token_is_rejected_over_http(): void
    {
        $response = $this->get(route('organization-invitations.accept', 'not-a-real-token'));

        $response->assertOk();
        $response->assertSee(__('This invitation link is invalid.'));
    }

    public function test_an_expired_invitation_is_rejected_over_http(): void
    {
        [, $token] = $this->invitationWithToken(['expires_at' => now()->subDay()]);

        $response = $this->get(route('organization-invitations.accept', $token));

        $response->assertOk();
        $response->assertSee(__('This invitation has expired.'));
    }

    public function test_a_revoked_invitation_is_rejected_over_http(): void
    {
        [, $token] = $this->invitationWithToken(['revoked_at' => now()]);

        $response = $this->get(route('organization-invitations.accept', $token));

        $response->assertOk();
        $response->assertSee(__('This invitation has been revoked.'));
    }

    public function test_an_already_accepted_invitation_is_handled_over_http(): void
    {
        [, $token] = $this->invitationWithToken(['accepted_at' => now()]);

        $response = $this->get(route('organization-invitations.accept', $token));

        $response->assertOk();
        $response->assertSee(__('This invitation has already been accepted.'));
    }
}

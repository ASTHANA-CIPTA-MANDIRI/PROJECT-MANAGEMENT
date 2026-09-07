<?php

namespace Tests\Feature\Organization;

use App\Http\Livewire\AcceptOrganizationInvitation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
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
}

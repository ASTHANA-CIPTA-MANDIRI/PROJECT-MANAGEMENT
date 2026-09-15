<?php

namespace Tests\Feature\Organization;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.4, action unified into "addMember" by Phase 5.4.1 —
 * Organization Invitation creation/revocation. Covers the brief's A-J, U,
 * V, W, X list directly against the real Livewire header action
 * (App\Filament\Pages\OrganizationSettings::getTableHeaderActions()'s
 * single "addMember" action's new-recipient branch), since invitation
 * creation deliberately reuses OrganizationPolicy::addMember() rather than
 * a separate authorization path — this suite proves that reuse holds
 * end-to-end, not just in isolation. Owner is no longer an option at all
 * (Phase 5.4.1 — ownership assignment is a distinct, not-yet-built
 * feature), so the old "owner can invite another owner" case is replaced
 * by an explicit denial test.
 *
 * Second revision: the form's "Role" field is access_role_id (a Spatie
 * Role id) - see OrganizationSettings::organizationRoleFor(). The
 * organization_invitations.role COLUMN is still the plain owner/admin/
 * member string it always was (derived from the picked Role, never
 * touched directly by this form anymore), so every assertion against that
 * column is unchanged; only the *submitted* payloads below changed.
 */
class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function panelUser(): User
    {
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    /**
     * A seeded Role named exactly "Owner"/"Admin"/"Member" - the only
     * names OrganizationSettings::organizationRoleFor() maps back to an
     * Organization authority tier.
     */
    private function accessRoleId(string $name): int
    {
        return Role::firstOrCreate(['name' => $name])->id;
    }

    // ---------------------------------------------------------------- A, B, C

    public function test_owner_can_invite_a_member(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Member')])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'invitee@example.test',
            'role' => 'member',
            'created_by' => $owner->id,
        ]);
        Notification::assertSentOnDemand(OrganizationInvitationCreated::class);
    }

    public function test_owner_can_invite_an_admin(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Admin')])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('organization_invitations', ['email' => 'invitee@example.test', 'role' => 'admin']);
    }

    public function test_owner_cannot_invite_an_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Owner')])
            ->assertForbidden();

        $this->assertDatabaseMissing('organization_invitations', ['email' => 'invitee@example.test']);
    }

    // ------------------------------------------------------------------- D, E

    public function test_admin_can_invite_a_member(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $organization->users()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Member')])
            ->assertHasNoTableActionErrors();
    }

    public function test_admin_can_invite_another_admin(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $organization->users()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Admin')])
            ->assertHasNoTableActionErrors();
    }

    // ---------------------------------------------------------------------- F

    public function test_admin_cannot_invite_an_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->panelUser();
        $organization->users()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => $this->accessRoleId('Owner')])
            ->assertForbidden();

        $this->assertDatabaseMissing('organization_invitations', ['email' => 'invitee@example.test']);
    }

    // ---------------------------------------------------------------------- G

    public function test_member_cannot_invite_anyone(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->panelUser();
        $organization->users()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(OrganizationSettings::class)
            ->assertTableActionHidden('addMember');

        $this->assertFalse(Gate::forUser($member)->allows('addMember', [$organization, 'member']));
    }

    // ---------------------------------------------------------------------- H

    public function test_a_user_with_no_organization_cannot_reach_the_invite_action(): void
    {
        $user = $this->panelUser();
        $this->actingAs($user);

        // No Organization at all -> OrganizationSettings::mount() 404s
        // before the page (and therefore the addMember action) is ever
        // reachable, exactly like every other Organization-scoped action.
        Livewire::test(OrganizationSettings::class)->assertNotFound();
    }

    // -------------------------------------------------------------------- U

    /**
     * The free-text 'role' field is gone - the equivalent tampering
     * surface now is a nonexistent access_role_id, which resolveAccessRole()
     * must refuse with a form validation error rather than creating an
     * invitation anyway.
     */
    public function test_a_nonexistent_access_role_id_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'invitee@example.test', 'access_role_id' => 999999])
            ->assertHasTableActionErrors(['access_role_id']);

        $this->assertDatabaseMissing('organization_invitations', ['email' => 'invitee@example.test']);
    }

    // -------------------------------------------------------------------- V

    /**
     * There is no organization_id field on this form at all - the action
     * always creates the invitation under $this->organization()
     * (OrganizationContext), never anything a client could smuggle in
     * alongside email/role.
     */
    public function test_invitation_organization_always_comes_from_context_never_the_payload(): void
    {
        Notification::fake();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $owner = $this->panelUser();
        $organizationA->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);
        \App\Support\OrganizationContext::switch($owner, $organizationA->id);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: [
                'name' => 'Invitee Person',
                'email' => 'invitee@example.test',
                'access_role_id' => $this->accessRoleId('Member'),
                'organization_id' => $organizationB->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'invitee@example.test',
            'organization_id' => $organizationA->id,
        ]);
        $this->assertDatabaseMissing('organization_invitations', [
            'email' => 'invitee@example.test',
            'organization_id' => $organizationB->id,
        ]);
    }

    // ----------------------------------------------------------- duplicates

    public function test_inviting_an_existing_member_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $existingMember = User::factory()->create(['email' => 'existing@example.test']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $organization->users()->attach($existingMember->id, ['role' => 'member']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'existing@example.test', 'access_role_id' => $this->accessRoleId('Admin')])
            ->assertHasTableActionErrors(['email']);

        $this->assertDatabaseMissing('organization_invitations', ['email' => 'existing@example.test']);
    }

    public function test_inviting_the_same_email_twice_while_pending_is_rejected(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'dup@example.test', 'access_role_id' => $this->accessRoleId('Member')])
            ->assertHasNoTableActionErrors();

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: ['name' => 'Invitee Person', 'email' => 'dup@example.test', 'access_role_id' => $this->accessRoleId('Admin')])
            ->assertHasTableActionErrors(['email']);

        $this->assertSame(1, OrganizationInvitation::where('email', 'dup@example.test')->count());
    }

    // ---------------------------------------------------- Phase 5.4.2: name

    public function test_the_invitation_stores_the_submitted_name(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->callTableAction('addMember', null, data: [
                'name' => 'Budi Santoso',
                'email' => 'budi@example.test',
                'access_role_id' => $this->accessRoleId('Member'),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'budi@example.test',
            'name' => 'Budi Santoso',
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);
    }

    // --------------------------------------------------------------- W, X

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $invitation = OrganizationInvitation::factory()->for($organization)->create();
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('revokeInvitation', $invitation->id);

        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_member_cannot_revoke_an_invitation(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->panelUser();
        $organization->users()->attach($member->id, ['role' => 'member']);
        $invitation = OrganizationInvitation::factory()->for($organization)->create();
        $this->actingAs($member);

        $this->assertFalse(Gate::forUser($member)->allows('revokeInvitation', $organization));

        Livewire::test(OrganizationSettings::class)
            ->call('revokeInvitation', $invitation->id)
            ->assertForbidden();

        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_admin_of_organization_a_cannot_revoke_an_invitation_from_organization_b(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->panelUser();
        $organizationA->users()->attach($adminA->id, ['role' => 'admin']);
        $invitationB = OrganizationInvitation::factory()->for($organizationB)->create();
        $this->actingAs($adminA);

        // Never even resolved - the lookup itself is scoped to the actor's
        // own current organization's invitations.
        Livewire::test(OrganizationSettings::class)->call('revokeInvitation', $invitationB->id);

        $this->assertNull($invitationB->fresh()->revoked_at);
    }

    public function test_revoking_an_already_accepted_invitation_is_a_no_op(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->panelUser();
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $invitation = OrganizationInvitation::factory()->for($organization)->accepted()->create();
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)->call('revokeInvitation', $invitation->id);

        $this->assertNull($invitation->fresh()->revoked_at);
    }

    // ------------------------------------------------------------ UI listing

    public function test_pending_invitations_are_listed_for_the_current_organization_only(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $owner = $this->panelUser();
        $organizationA->users()->attach($owner->id, ['role' => 'owner']);
        OrganizationInvitation::factory()->for($organizationA)->create(['email' => 'a@example.test']);
        OrganizationInvitation::factory()->for($organizationB)->create(['email' => 'b@example.test']);
        OrganizationInvitation::factory()->for($organizationA)->accepted()->create(['email' => 'accepted@example.test']);
        $this->actingAs($owner);

        Livewire::test(OrganizationSettings::class)
            ->assertSee('a@example.test')
            ->assertDontSee('b@example.test')
            ->assertDontSee('accepted@example.test');
    }
}

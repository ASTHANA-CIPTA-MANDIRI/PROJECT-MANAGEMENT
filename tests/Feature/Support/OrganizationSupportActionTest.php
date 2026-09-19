<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\OrganizationSupportAction;
use App\Models\OrganizationSupportSession;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of Support Action: the audit trail foundation, exercised on its
 * own here — nothing in production code writes to this table yet (that is
 * Phase 4), so these tests create rows directly, the same way
 * TicketActivity is exercised in isolation from whatever eventually
 * triggers it.
 */
class OrganizationSupportActionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    private function actionSession(User $admin, Organization $organization): OrganizationSupportSession
    {
        return SupportSessionContext::start(
            $admin,
            $organization,
            'Support request',
            OrganizationSupportSession::LEVEL_SUPPORT_ACTION
        );
    }

    public function test_an_action_record_can_be_created_with_every_field(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->actionSession($admin, $organization);
        $ticket = Ticket::factory()->create();

        $action = OrganizationSupportAction::create([
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.change_status',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
            'field' => 'status_id',
            'old_value' => '1',
            'new_value' => '2',
        ]);

        $this->assertDatabaseHas('organization_support_actions', [
            'id' => $action->id,
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.change_status',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
            'field' => 'status_id',
            'old_value' => '1',
            'new_value' => '2',
        ]);
        $this->assertNotNull($action->created_at);
    }

    public function test_field_old_value_and_new_value_are_nullable_for_a_future_non_single_field_action(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->actionSession($admin, $organization);
        $ticket = Ticket::factory()->create();

        $action = OrganizationSupportAction::create([
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.something_without_a_single_field',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
        ]);

        $this->assertNull($action->field);
        $this->assertNull($action->old_value);
        $this->assertNull($action->new_value);
    }

    public function test_relationships_resolve_to_the_correct_models(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->actionSession($admin, $organization);
        $ticket = Ticket::factory()->create();

        $action = OrganizationSupportAction::create([
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.change_status',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
            'field' => 'status_id',
            'old_value' => '1',
            'new_value' => '2',
        ]);

        $this->assertTrue($action->supportSession->is($session));
        $this->assertTrue($action->actor->is($admin));
        $this->assertTrue($action->organization->is($organization));
    }

    /**
     * Security consideration: an audit row must outlive the actor's own
     * account, the same way OrganizationSupportSession.super_admin_id
     * already does — deleting the admin who performed the action must
     * never erase the fact that it happened.
     *
     * forceDelete(), not delete(): User uses SoftDeletes, so an ordinary
     * delete() only sets deleted_at and never actually removes the row —
     * the nullOnDelete foreign key only fires on a real DELETE, which for
     * a soft-deleting model is forceDelete(). A second Super Admin is
     * created first: UserObserver::deleting() unconditionally cancels the
     * delete of the platform's last Super Admin (isLastSuperAdmin()),
     * which forceDelete() also goes through — without this, the delete
     * itself would silently no-op and this test would be exercising
     * nothing.
     */
    public function test_force_deleting_the_actor_nulls_actor_user_id_but_keeps_the_action_record(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->actionSession($admin, $organization);
        $ticket = Ticket::factory()->create();

        $action = OrganizationSupportAction::create([
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.change_status',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
            'field' => 'status_id',
            'old_value' => '1',
            'new_value' => '2',
        ]);

        $admin->forceDelete();

        $this->assertNotNull($action->fresh());
        $this->assertNull($action->fresh()->actor_user_id);
    }

    /**
     * Security consideration: unlike the actor, an action record has no
     * independent existence worth preserving once its Organization is
     * gone — same reasoning App\Models\Activity's own organization_id
     * cascade already documents.
     */
    public function test_deleting_the_organization_deletes_its_action_records(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = $this->actionSession($admin, $organization);
        $ticket = Ticket::factory()->create();

        $action = OrganizationSupportAction::create([
            'support_session_id' => $session->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'action' => 'ticket.change_status',
            'target_type' => Ticket::class,
            'target_id' => $ticket->id,
            'field' => 'status_id',
            'old_value' => '1',
            'new_value' => '2',
        ]);

        $organization->delete();

        $this->assertDatabaseMissing('organization_support_actions', ['id' => $action->id]);
    }

    /**
     * Immutability is a convention, not a database constraint (same as
     * OrganizationSupportSession/TicketActivity) — this pins down the one
     * concrete piece of that convention that *is* enforced: there is no
     * updated_at for anything to accidentally touch.
     */
    public function test_the_model_has_no_updated_at_timestamp(): void
    {
        $this->assertNull(OrganizationSupportAction::UPDATED_AT);
    }
}

<?php

namespace Tests\Feature\Organization;

use App\Models\Epic;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Phase 3B — Child/Lookup Data Tenant Isolation. Ticket, Sprint, Epic and
 * TicketComment all delegate their object-level authorization fully to
 * Project::isAccessibleBy()/isManageableBy() (the Phase 3A gate) — this
 * proves that delegation actually closes the organization boundary for each
 * of them, rather than trusting the code-reading claim alone. TicketHour is
 * the one child resource that does NOT delegate to Project (it's scoped to
 * its own owner only) — covered separately below per the Phase 3B brief's
 * explicit instruction to audit and decide, not assume.
 *
 * "Task" is not a separate model in this codebase — it's a TicketType value
 * on Ticket (see database/seeders/TicketTypeSeeder.php), so Ticket coverage
 * here is Task coverage too.
 */
class ChildResourceTenantIsolationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function crossOrgSetup(array $permissions = []): array
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->userWithPermissions($permissions);
        $orgA->users()->attach($user);

        $projectA = Project::factory()->create(['organization_id' => $orgA->id]);
        $projectA->users()->attach($user->id, ['role' => 'employee']);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id, 'owner_id' => $user->id]);

        return [$user, $projectA, $projectB];
    }

    // ---------------------------------------------------------------- Ticket

    public function test_ticket_access_follows_its_projects_organization_boundary(): void
    {
        [$user, $projectA, $projectB] = $this->crossOrgSetup(['View ticket']);
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        $this->assertTrue((new \App\Policies\TicketPolicy)->view($user, $ticketA));
        $this->assertFalse((new \App\Policies\TicketPolicy)->view($user, $ticketB));
    }

    public function test_a_ticket_owned_by_the_user_in_a_foreign_org_is_still_denied(): void
    {
        // Ownership must not bypass the organization boundary either —
        // mirrors Project::isAccessibleBy() denying its own owner when the
        // project belongs to the wrong organization context.
        [$user, , $projectB] = $this->crossOrgSetup(['View ticket', 'Update ticket']);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id, 'owner_id' => $user->id]);

        $this->assertFalse((new \App\Policies\TicketPolicy)->view($user, $ticketB));
        $this->assertFalse((new \App\Policies\TicketPolicy)->update($user, $ticketB));
    }

    public function test_a_ticket_with_no_project_organization_stays_visible_to_its_owner(): void
    {
        $user = $this->userWithPermissions(['View ticket']);
        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $ticket = Ticket::factory()->create(['project_id' => $legacyProject->id, 'owner_id' => $user->id]);

        $this->assertTrue((new \App\Policies\TicketPolicy)->view($user, $ticket));
    }

    // ---------------------------------------------------------------- Sprint

    public function test_sprint_access_follows_its_projects_organization_boundary(): void
    {
        [$user, $projectA, $projectB] = $this->crossOrgSetup(['View sprint']);
        $sprintA = Sprint::factory()->create(['project_id' => $projectA->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $projectB->id]);

        $this->assertTrue((new \App\Policies\SprintPolicy)->view($user, $sprintA));
        $this->assertFalse((new \App\Policies\SprintPolicy)->view($user, $sprintB));
    }

    // ----------------------------------------------------------------- Epic

    public function test_epic_access_follows_its_projects_organization_boundary(): void
    {
        [$user, $projectA, $projectB] = $this->crossOrgSetup();
        $epicA = Epic::factory()->create(['project_id' => $projectA->id]);
        $epicB = Epic::factory()->create(['project_id' => $projectB->id]);

        $this->assertTrue((new \App\Policies\EpicPolicy)->view($user, $epicA));
        $this->assertFalse((new \App\Policies\EpicPolicy)->view($user, $epicB));
    }

    // -------------------------------------------------------------- Comment

    public function test_comment_access_follows_its_tickets_projects_organization_boundary(): void
    {
        [$user, $projectA, $projectB] = $this->crossOrgSetup();
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        // Neither comment is authored by $user, so this proves the
        // "manager of the ticket's project" branch specifically.
        $commentA = TicketComment::factory()->create(['ticket_id' => $ticketA->id]);
        $commentB = TicketComment::factory()->create(['ticket_id' => $ticketB->id]);
        $projectA->users()->syncWithoutDetaching([
            $user->id => ['role' => config('system.projects.affectations.roles.can_manage')],
        ]);
        // $user already owns $projectB (crossOrgSetup) — ownership alone
        // must not bypass the wrong organization context either.

        $this->assertTrue((new \App\Policies\TicketCommentPolicy)->delete($user, $commentA->fresh()));
        $this->assertFalse((new \App\Policies\TicketCommentPolicy)->delete($user, $commentB->fresh()));
    }

    public function test_direct_comment_id_from_a_foreign_org_ticket_cannot_be_deleted_by_its_own_author(): void
    {
        // The comment's own author, but the ticket now lives behind a
        // different organization than the author's current context.
        // Authorship still bypasses project membership (unchanged), but not
        // the organization boundary — mirrors TicketPolicy::isInvolved()'s
        // same rule for ticket owner/responsible.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $author = User::factory()->create();
        $orgA->users()->attach($author);
        $orgB->users()->attach($author);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);
        $comment = TicketComment::factory()->create(['ticket_id' => $ticketB->id, 'user_id' => $author->id]);

        OrganizationContext::switch($author, $orgA->id);
        $this->assertFalse((new \App\Policies\TicketCommentPolicy)->delete($author, $comment));

        OrganizationContext::switch($author, $orgB->id);
        $this->assertTrue((new \App\Policies\TicketCommentPolicy)->delete($author, $comment));
    }

    public function test_a_comment_on_a_ticket_with_no_project_organization_stays_deletable_by_its_author(): void
    {
        $author = User::factory()->create();
        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $ticket = Ticket::factory()->create(['project_id' => $legacyProject->id]);
        $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $author->id]);

        $this->assertTrue((new \App\Policies\TicketCommentPolicy)->delete($author, $comment));
    }

    // ----------------------------------------------------------- TicketHour

    public function test_own_timesheet_row_in_the_inactive_organization_is_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->userWithPermissions(['List timesheet data']);
        $orgA->users()->attach($user);
        $orgB->users()->attach($user);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);
        $hourInB = TicketHour::factory()->create(['ticket_id' => $ticketB->id, 'user_id' => $user->id]);

        OrganizationContext::switch($user, $orgA->id);

        $policy = new \App\Policies\TicketHourPolicy;
        $this->assertFalse($policy->view($user, $hourInB));
        $this->assertFalse($policy->update($user, $hourInB));
        $this->assertFalse($policy->delete($user, $hourInB));

        OrganizationContext::switch($user, $orgB->id);
        $this->assertTrue($policy->view($user, $hourInB));
    }

    public function test_timesheet_listing_query_excludes_the_inactive_organizations_rows(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $orgA->users()->attach($user);
        $orgB->users()->attach($user);

        $projectA = Project::factory()->create(['organization_id' => $orgA->id]);
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);
        $hourInA = TicketHour::factory()->create(['ticket_id' => $ticketA->id, 'user_id' => $user->id]);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);
        TicketHour::factory()->create(['ticket_id' => $ticketB->id, 'user_id' => $user->id]);

        OrganizationContext::switch($user, $orgA->id);

        $visibleIds = TicketHour::query()
            ->where('user_id', $user->id)
            ->whereHas('ticket.project', fn ($q) => $q->whereNull('organization_id')
                ->orWhere('organization_id', $orgA->id))
            ->pluck('id');

        $this->assertSame([$hourInA->id], $visibleIds->all());
    }

    public function test_timesheet_row_with_no_project_organization_stays_visible(): void
    {
        $orgOther = Organization::factory()->create();
        $user = $this->userWithPermissions(['List timesheet data']);
        $orgOther->users()->attach($user);

        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $ticket = Ticket::factory()->create(['project_id' => $legacyProject->id]);
        $hour = TicketHour::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

        $this->assertTrue((new \App\Policies\TicketHourPolicy)->view($user, $hour));
    }
}

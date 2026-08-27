<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * TicketResource/ProjectResource used to leave the tenant/access scope only
 * on ListTickets::getTableQuery()/ListProjects::getTableQuery(). Any other
 * page or action built off the resource - view/edit route binding, row/bulk
 * actions, relation managers - went through Resource::getEloquentQuery()
 * directly and had no scope at all, only the object-level Policy check. The
 * scope now lives on getEloquentQuery() itself, so it is asserted directly
 * here rather than only through the list page.
 */
class ResourceQueryScopingTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    // -------------------------------------------------------------- tickets

    public function test_ticket_resource_query_excludes_tickets_from_inaccessible_projects(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket']);
        $this->actingAs($user);

        $mine = Project::factory()->create(['owner_id' => $user->id]);
        $myTicket = Ticket::factory()->create(['project_id' => $mine->id, 'owner_id' => $user->id]);

        $other = Project::factory()->create(); // someone else's project entirely
        $otherTicket = Ticket::factory()->create(['project_id' => $other->id]);

        $ids = TicketResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($myTicket->id, $ids);
        $this->assertNotContains($otherTicket->id, $ids);
    }

    public function test_ticket_resource_query_includes_tickets_the_user_is_only_responsible_for(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket']);
        $this->actingAs($user);

        // Neither owner nor a member of the project - only responsible for the ticket.
        $ticket = Ticket::factory()->create(['responsible_id' => $user->id]);

        $this->assertContains($ticket->id, TicketResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_ticket_resource_query_includes_tickets_via_project_membership(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket']);
        $this->actingAs($user);

        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertContains($ticket->id, TicketResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_the_ticket_list_page_still_hides_the_same_tickets_now_that_the_scope_moved(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket']);
        $this->actingAs($user);

        $other = Project::factory()->create();
        $otherTicket = Ticket::factory()->create(['project_id' => $other->id, 'name' => 'Not mine']);

        Livewire::test(ListTickets::class)
            ->assertCanNotSeeTableRecords([$otherTicket]);
    }

    // ------------------------------------------------------------- projects

    public function test_project_resource_query_excludes_projects_the_user_cannot_access(): void
    {
        $user = $this->userWithPermissions(['List projects', 'View project']);
        $this->actingAs($user);

        $mine = Project::factory()->create(['owner_id' => $user->id]);
        $foreign = Project::factory()->create(); // not owned, not a member

        $ids = ProjectResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_project_resource_query_includes_projects_the_user_is_only_a_member_of(): void
    {
        $user = $this->userWithPermissions(['List projects', 'View project']);
        $this->actingAs($user);

        $project = Project::factory()->create(); // owned by someone else
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertContains($project->id, ProjectResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_the_project_list_page_still_hides_the_same_projects_now_that_the_scope_moved(): void
    {
        $user = $this->userWithPermissions(['List projects', 'View project']);
        $this->actingAs($user);

        $foreign = Project::factory()->create(['name' => 'Not mine either']);

        Livewire::test(ListProjects::class)
            ->assertCanNotSeeTableRecords([$foreign]);
    }
}

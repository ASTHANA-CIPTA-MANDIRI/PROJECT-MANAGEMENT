<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Kanban;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Http\Livewire\RoadMap\IssueForm;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Support\UserOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Every "pick a user" select used to be filled with User::all(): the whole
 * installation's directory, whoever was looking. That leaked the name and id
 * of users from unrelated projects, and let a ticket be assigned to somebody
 * outside its project. Options are now scoped through UserOptions.
 */
class UserOptionScopingTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private User $user;

    private User $mate;

    private User $colleague;

    private User $stranger;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = $this->userWithPermissions([
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
        ]);

        $this->mate = User::factory()->create(['name' => 'Rekan Satu Proyek']);
        $this->colleague = User::factory()->create(['name' => 'Rekan Proyek Lain']);
        $this->stranger = User::factory()->create(['name' => 'Orang Luar Instalasi']);

        // A project the actor owns, with one team mate.
        $this->project = Project::factory()->create(['owner_id' => $this->user->id]);
        $this->project->users()->attach($this->mate->id, ['role' => 'member']);

        // A second project the actor is a member of, with a different mate:
        // visible to the actor, but not a contributor of $this->project.
        $otherProject = Project::factory()->create();
        $otherProject->users()->attach([$this->user->id, $this->colleague->id], ['role' => 'member']);

        // And a project the actor has nothing to do with.
        Project::factory()->create(['owner_id' => $this->stranger->id]);

        $this->actingAs($this->user);
    }

    private function seedLookups(): void
    {
        TicketStatus::factory()->default()->create(['project_id' => null]);
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
    }

    // ------------------------------------------------------------ UserOptions

    public function test_visible_options_exclude_users_from_unrelated_projects(): void
    {
        $options = UserOptions::visible();

        $this->assertArrayHasKey($this->user->id, $options);
        $this->assertArrayHasKey($this->mate->id, $options);
        $this->assertArrayHasKey($this->colleague->id, $options);
        $this->assertArrayNotHasKey($this->stranger->id, $options);
    }

    public function test_project_options_are_limited_to_that_projects_contributors(): void
    {
        $options = UserOptions::forProject($this->project);

        $this->assertSame(
            [$this->user->id, $this->mate->id],
            collect(array_keys($options))->sort()->values()->all()
        );
    }

    public function test_project_options_keep_an_assignee_who_left_the_project(): void
    {
        $options = UserOptions::forProject($this->project, $this->colleague->id);

        $this->assertArrayHasKey($this->colleague->id, $options);
        $this->assertArrayNotHasKey($this->stranger->id, $options);
    }

    public function test_a_project_id_the_user_cannot_access_does_not_expose_its_members(): void
    {
        $foreign = Project::factory()->create(['owner_id' => $this->stranger->id]);

        $options = UserOptions::forProjectId($foreign->id);

        $this->assertArrayNotHasKey($this->stranger->id, $options);
    }

    public function test_options_without_a_project_fall_back_to_the_visible_users(): void
    {
        $this->assertSame(UserOptions::visible(), UserOptions::forProjectId(null));
    }

    // ------------------------------------------------------------------ Forms

    public function test_the_ticket_form_does_not_list_the_whole_directory(): void
    {
        $this->seedLookups();

        Livewire::test(CreateTicket::class)
            ->assertDontSee($this->stranger->name)
            ->set('data.project_id', $this->project->id)
            ->assertSee($this->mate->name)
            ->assertDontSee($this->colleague->name)
            ->assertDontSee($this->stranger->name);
    }

    public function test_the_road_map_issue_form_does_not_list_the_whole_directory(): void
    {
        $this->seedLookups();

        Livewire::test(IssueForm::class, ['project' => $this->project])
            ->assertSee($this->mate->name)
            ->assertDontSee($this->colleague->name)
            ->assertDontSee($this->stranger->name);
    }

    public function test_the_issue_form_ignores_a_project_the_user_cannot_access(): void
    {
        $this->seedLookups();
        $foreign = Project::factory()->create(['owner_id' => $this->stranger->id]);

        Livewire::test(IssueForm::class, ['project' => $foreign])
            ->assertDontSee($this->stranger->name);
    }

    public function test_the_board_filter_only_offers_the_projects_contributors(): void
    {
        $this->seedLookups();
        Ticket::factory()->create([
            'project_id' => $this->project->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(Kanban::class, ['project' => $this->project])
            ->assertSee($this->mate->name)
            ->assertDontSee($this->colleague->name)
            ->assertDontSee($this->stranger->name);
    }

    public function test_the_ticket_list_status_filter_does_not_list_other_tenants_statuses(): void
    {
        $ownStatus = TicketStatus::factory()->create([
            'project_id' => $this->project->id,
            'name' => 'Status Proyek Sendiri',
        ]);
        $globalStatus = TicketStatus::factory()->create([
            'project_id' => null,
            'name' => 'Status Bawaan Global',
        ]);
        $foreignProject = Project::factory()->create(['owner_id' => $this->stranger->id]);
        $foreignStatus = TicketStatus::factory()->create([
            'project_id' => $foreignProject->id,
            'name' => 'Status Proyek Asing',
        ]);

        Livewire::test(TicketResource\Pages\ListTickets::class)
            ->assertSee($ownStatus->name)
            ->assertSee($globalStatus->name)
            ->assertDontSee($foreignStatus->name);
    }
}

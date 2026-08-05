<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\RoadMap\EpicForm;
use App\Http\Livewire\RoadMap\IssueForm;
use App\Http\Livewire\Ticket\Attachments;
use App\Http\Livewire\Timesheet\TimeLogged;
use App\Models\Epic;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComponentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = [
            'List projects', 'View project', 'Update project',
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
            'List sprints', 'View sprint', 'Create sprint', 'Update sprint',
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Member']);
        $role->syncPermissions($names);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    // -------------------------------------------------------- road map forms

    public function test_the_epic_form_renders_for_a_new_epic(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = new Epic(['project_id' => $project->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])->assertSuccessful();
    }

    public function test_the_epic_form_renders_for_an_existing_epic(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])->assertSuccessful();
    }

    public function test_the_epic_form_renders_when_sibling_epics_exist(): void
    {
        // Exercises the mount() branch that filters the epic itself out of the
        // selectable parents.
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        Epic::factory()->count(2)->create(['project_id' => $project->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])->assertSuccessful();
    }

    public function test_the_epic_form_renders_alongside_epics_of_other_projects(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id]);
        $foreign = Project::factory()->create();
        $epic = Epic::factory()->create(['project_id' => $mine->id]);
        Epic::factory()->create(['project_id' => $mine->id]);
        Epic::factory()->create(['project_id' => $foreign->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])->assertSuccessful();
    }

    public function test_the_issue_form_renders_for_a_new_ticket(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = new Ticket(['project_id' => $project->id]);

        Livewire::test(IssueForm::class, ['ticket' => $ticket])->assertSuccessful();
    }

    public function test_the_issue_form_renders_for_an_existing_ticket(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(IssueForm::class, ['ticket' => $ticket])->assertSuccessful();
    }

    public function test_the_issue_form_renders_for_a_ticket_inside_a_sprint(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(IssueForm::class, ['ticket' => $ticket])->assertSuccessful();
    }

    // ----------------------------------------------------------- attachments

    public function test_the_attachments_component_renders(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(Attachments::class, ['ticket' => $ticket])->assertSuccessful();
    }

    public function test_the_attachments_component_rejects_a_disallowed_file_type(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->set('attachments', [UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml')])
            ->call('perform')
            ->assertHasErrors(['attachments']);
    }

    public function test_the_attachments_component_rejects_a_file_over_the_max_size(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->set('attachments', [
                UploadedFile::fake()->create('big.pdf', config('system.max_file_size') + 1, 'application/pdf'),
            ])
            ->call('perform')
            ->assertHasErrors(['attachments']);
    }

    public function test_the_attachments_component_accepts_an_allowed_file(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->set('attachments', [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')])
            ->call('perform')
            ->assertHasNoErrors();
    }

    // ---------------------------------------------------------- time logged

    public function test_the_time_logged_table_renders(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])->assertSuccessful();
    }

    public function test_the_time_logged_table_lists_logged_entries(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);
        TicketHour::factory()->count(3)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])->assertSuccessful();
    }

    public function test_the_time_logged_table_renders_when_nothing_is_logged(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(TimeLogged::class, ['ticket' => $ticket])->assertSuccessful();
    }
}

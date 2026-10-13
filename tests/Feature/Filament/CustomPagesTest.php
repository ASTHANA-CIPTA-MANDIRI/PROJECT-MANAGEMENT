<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Board;
use App\Filament\Pages\Kanban;
use App\Filament\Pages\RoadMap;
use App\Filament\Pages\Scrum;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The board pages carry the heaviest custom logic in the panel: they build
 * their columns through KanbanScrumHelper. Rendering them with real projects
 * exercises that grouping code end to end.
 */
class CustomPagesTest extends TestCase
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

    private function kanbanProject(): Project
    {
        return Project::factory()->create(['owner_id' => $this->user->id]);
    }

    private function scrumProject(): Project
    {
        return Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
    }

    // ---------------------------------------------------------------- kanban

    public function test_the_kanban_board_renders_for_a_kanban_project(): void
    {
        $project = $this->kanbanProject();

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_renders_with_tickets(): void
    {
        $project = $this->kanbanProject();
        Ticket::factory()->count(5)->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_renders_for_an_empty_project(): void
    {
        Livewire::test(Kanban::class, ['project' => $this->kanbanProject()])
            ->assertSuccessful();
    }

    public function test_a_project_member_can_open_the_kanban_board(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'employee']);

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_subscribes_to_live_project_updates(): void
    {
        $project = $this->kanbanProject();

        $listeners = Livewire::test(Kanban::class, ['project' => $project])
            ->instance()
            ->getListeners();

        $this->assertArrayHasKey("echo-private:project.{$project->id},.ticket.status.changed", $listeners);
        $this->assertArrayHasKey("echo-private:project.{$project->id},.ticket.comment.posted", $listeners);
        $this->assertSame('refreshBoard', $listeners["echo-private:project.{$project->id},.ticket.status.changed"]);
    }

    public function test_a_live_broadcast_refreshes_the_kanban_board(): void
    {
        $project = $this->kanbanProject();

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('refreshBoard')
            ->assertSuccessful();
    }

    public function test_kanban_cards_carry_a_cover_image_and_a_plain_text_excerpt(): void
    {
        $project = $this->kanbanProject();
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'content' => '<p>Write the <strong>landing</strong> copy.</p>'
                .'<a href="https://example.test/a.png"><img src="https://example.test/a.png" alt="a.png" />'
                .'<span>shot.png</span> <span>51 KB</span></a>',
        ]);
        $plain = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'content' => '<p>No picture here.</p>',
        ]);

        $records = Livewire::test(Kanban::class, ['project' => $project])->instance()->getRecords()->keyBy('id');

        $this->assertSame('https://example.test/a.png', $records[$ticket->id]['cover']);
        // Attachment chips (file name + size) never leak into the excerpt.
        $this->assertSame('Write the landing copy.', $records[$ticket->id]['excerpt']);
        $this->assertNull($records[$plain->id]['cover']);
        $this->assertSame('No picture here.', $records[$plain->id]['excerpt']);
    }

    public function test_the_kanban_board_filters_tickets_by_label(): void
    {
        $project = $this->kanbanProject();
        $label = \App\Models\Label::factory()->create();

        $labeled = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $this->user->id]);
        $labeled->labels()->attach($label);
        $plain = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $this->user->id]);

        $board = Livewire::test(Kanban::class, ['project' => $project]);
        $board->set('labels', [$label->id]);
        $ids = $board->instance()->getRecords()->pluck('id');

        $this->assertTrue($ids->contains($labeled->id), 'labelled ticket should show');
        $this->assertFalse($ids->contains($plain->id), 'unlabelled ticket should be filtered out');
    }

    public function test_the_kanban_board_queries_the_ticket_list_once_for_the_whole_board(): void
    {
        $project = $this->kanbanProject();
        foreach (TicketStatus::factory()->count(5)->create() as $status) {
            Ticket::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $this->user->id,
                'status_id' => $status->id,
            ]);
        }

        $listQueries = $this->countTicketListQueries(
            fn () => Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful()
        );

        // One query for the whole board, not one per column.
        $this->assertSame(1, $listQueries, "the board ran {$listQueries} ticket-list queries");
    }

    public function test_the_kanban_board_puts_each_ticket_in_its_own_status_column(): void
    {
        $project = $this->kanbanProject();
        [$todo, $doing] = TicketStatus::factory()->count(2)->create()->all();

        $first = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'status_id' => $todo->id,
        ]);
        $second = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'status_id' => $doing->id,
        ]);

        $html = Livewire::test(Kanban::class, ['project' => $project])->lastRenderedDom;

        $this->assertSame($todo->id, $this->columnHolding($html, $first->code));
        $this->assertSame($doing->id, $this->columnHolding($html, $second->code));
    }

    /**
     * Count the board's ticket-list queries (the grouped COUNT behind the
     * column headers is not one of them).
     */
    private function countTicketListQueries(callable $callback): int
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "tickets"') && ! str_contains($query->sql, 'count(*)')) {
                $queries++;
            }
        });

        $callback();

        return $queries;
    }

    /**
     * The id of the status column the given ticket code was rendered in.
     */
    private function columnHolding(string $html, string $code): ?int
    {
        $columns = preg_split('/id="status-records-(\d+)"/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i < count($columns); $i += 2) {
            if (str_contains($columns[$i + 1], $code)) {
                return (int) $columns[$i];
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- scrum

    public function test_the_scrum_board_renders_for_a_scrum_project(): void
    {
        $project = $this->scrumProject();

        Livewire::test(Scrum::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_scrum_board_renders_with_a_running_sprint(): void
    {
        $project = $this->scrumProject();
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        Ticket::factory()->count(3)->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(Scrum::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_scrum_board_queries_the_ticket_list_once_for_the_whole_board(): void
    {
        $project = $this->scrumProject();
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        foreach (TicketStatus::factory()->count(5)->create() as $status) {
            Ticket::factory()->create([
                'project_id' => $project->id,
                'sprint_id' => $sprint->id,
                'owner_id' => $this->user->id,
                'status_id' => $status->id,
            ]);
        }

        $listQueries = $this->countTicketListQueries(
            fn () => Livewire::test(Scrum::class, ['project' => $project])->assertSuccessful()
        );

        $this->assertSame(1, $listQueries, "the board ran {$listQueries} ticket-list queries");
    }

    public function test_the_scrum_board_renders_without_any_sprint(): void
    {
        Livewire::test(Scrum::class, ['project' => $this->scrumProject()])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- road map

    public function test_the_road_map_page_renders(): void
    {
        Livewire::test(RoadMap::class)->assertSuccessful();
    }

    public function test_the_road_map_renders_with_projects_and_sprints(): void
    {
        $project = $this->scrumProject();
        Sprint::factory()->count(2)->create(['project_id' => $project->id]);

        Livewire::test(RoadMap::class)->assertSuccessful();
    }

    // ----------------------------------------------------------------- board

    public function test_the_road_map_reloads_on_a_live_status_change_of_its_project(): void
    {
        $project = $this->kanbanProject();

        $page = Livewire::test(RoadMap::class, ['p' => $project->id])->instance();
        $page->project = $project;

        $listeners = $page->getListeners();

        $this->assertSame(
            'refreshRoadMap',
            $listeners["echo-private:project.{$project->id},.ticket.status.changed"]
        );
        // The existing dialog listeners are kept.
        $this->assertSame('closeDialog', $listeners['closeEpicDialog']);
    }

    public function test_the_board_page_renders(): void
    {
        Livewire::test(Board::class)->assertSuccessful();
    }

    public function test_the_board_page_renders_with_projects(): void
    {
        $this->kanbanProject();
        $this->scrumProject();

        Livewire::test(Board::class)->assertSuccessful();
    }

    public function test_the_board_redirects_to_the_chosen_project(): void
    {
        $project = $this->scrumProject();

        Livewire::test(Board::class)
            ->set('project', $project->id)
            ->assertRedirect(route('filament.pages.scrum/{project}', ['project' => $project]));
    }

    /**
     * The selected id arrives from the browser: a project the user cannot reach
     * must not be resolved, even though it never appears in the select.
     */
    public function test_the_board_refuses_a_project_the_user_cannot_access(): void
    {
        $foreign = Project::factory()->create();

        Livewire::test(Board::class)
            ->set('project', $foreign->id)
            ->assertNoRedirect();
    }

    /**
     * SweetAlert2 reads its default (translated) texts from this inline
     * object, so every panel page must render it with the app locale.
     */
    public function test_panel_pages_expose_translated_sweetalert_texts(): void
    {
        $this->get(Board::getUrl())
            ->assertOk()
            ->assertSee('window.RencanakanAlertTexts', false)
            ->assertSee('Apakah Anda yakin?', false)
            ->assertSee('Ya, lanjutkan', false);
    }

    /**
     * Destructive buttons in custom Blade views are gated by SweetAlert (the
     * dialog is UX only - the Livewire handlers keep their own authorization).
     * A leftover native confirm() would silently bypass the unified dialog.
     */
    public function test_destructive_blade_buttons_use_sweetalert_instead_of_native_confirm(): void
    {
        foreach ([
            'filament/pages/organization-settings',
            'filament/pages/organization-support-view',
            'livewire/road-map/epic-form',
            'filament/resources/tickets/view',
        ] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));

            $this->assertDoesNotMatchRegularExpression('/(?<![\w.])confirm\(@js|\bif \(confirm\(/', $source, "{$view} still calls native confirm().");
        }

        $this->assertStringContainsString('data-swal-confirm', file_get_contents(resource_path('views/livewire/road-map/epic-form.blade.php')));
        $this->assertStringContainsString('data-swal-confirm', file_get_contents(resource_path('views/filament/pages/organization-settings.blade.php')));
        $this->assertStringContainsString('data-swal-confirm', file_get_contents(resource_path('views/filament/resources/tickets/view.blade.php')));
        $this->assertStringContainsString('RencanakanAlert.confirm', file_get_contents(resource_path('views/filament/pages/organization-support-view.blade.php')));
    }
}

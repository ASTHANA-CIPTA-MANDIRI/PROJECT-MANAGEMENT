<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Kanban;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * M-4: Kanban cards/columns rendered without a stable wire:key could be
 * mismatched by Livewire's DOM diffing across a re-render, and
 * Sortable.create() only ran once on first page load - so drag-and-drop
 * quietly stopped working after any board refresh (a drag, a filter, or a
 * broadcast event such as ticket.status.changed/ticket.comment.posted).
 * These cases pin the markup and script that fix both.
 */
class KanbanSortableMarkupTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    public function test_cards_and_columns_render_with_a_stable_wire_key(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket', 'Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $status = TicketStatus::factory()->default()->create(['project_id' => null]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'status_id' => $status->id,
        ]);
        $this->actingAs($user);
        Notification::fake();

        Livewire::test(Kanban::class, ['project' => $project])
            ->assertSee("wire:key=\"status-{$status->id}\"", false)
            ->assertSee("wire:key=\"record-{$ticket->id}\"", false);
    }

    public function test_sortable_is_reinitialized_after_every_livewire_update_not_only_on_first_load(): void
    {
        $user = $this->userWithPermissions(['List tickets', 'View ticket', 'Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user);
        Notification::fake();

        // @push('scripts') content only appears in the full page (rendered
        // through the layout's @stack('scripts')), not in a bare
        // Livewire::test() component payload - a full HTTP request is needed
        // to see it.
        $response = $this->get(route('filament.pages.kanban/{project}', ['project' => $project]));

        // The Sortable.create() call must live inside a function invoked both
        // on first load and on every subsequent Livewire message - not just
        // once inside a bare IIFE (the pre-fix shape), which only ever runs
        // for the page's very first render.
        $response->assertSee('function initKanbanSortable', false);
        $response->assertSee("Livewire.hook('message.processed'", false);
        $response->assertSee('Sortable.get(el)', false);
    }
}

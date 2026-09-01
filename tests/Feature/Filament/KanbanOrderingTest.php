<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Kanban;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Dragging a card wrote the browser's index straight onto the ticket and moved
 * on: the other cards in the column kept their old numbers, and the board did
 * not sort by them at all, so the new arrangement was lost on the next reload.
 * The column is now renumbered as a whole and the board reads that order back.
 */
class KanbanOrderingTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private User $user;

    private Project $project;

    private TicketStatus $todo;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = $this->userWithPermissions([
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
        ]);
        $this->project = Project::factory()->create(['owner_id' => $this->user->id]);
        $this->todo = TicketStatus::factory()->default()->create(['project_id' => null]);

        $this->actingAs($this->user);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Ticket>
     */
    private function cards(int $count, ?TicketStatus $status = null)
    {
        return Ticket::factory()->count($count)->create([
            'project_id' => $this->project->id,
            'owner_id' => $this->user->id,
            'status_id' => ($status ?? $this->todo)->id,
        ]);
    }

    /**
     * The ids of a column in board order, straight from the database.
     *
     * @return array<int, int>
     */
    private function columnOrder(?TicketStatus $status = null): array
    {
        return Ticket::where('project_id', $this->project->id)
            ->where('status_id', ($status ?? $this->todo)->id)
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function drag(Ticket $ticket, int $toIndex, ?TicketStatus $toStatus = null): void
    {
        Livewire::test(Kanban::class, ['project' => $this->project])
            ->call('recordUpdated', $ticket->id, $toIndex, ($toStatus ?? $this->todo)->id)
            ->assertSuccessful();
    }

    public function test_moving_a_card_to_the_top_renumbers_the_whole_column(): void
    {
        [$first, $second, $third] = $this->cards(3)->all();

        $this->drag($third, 0);

        $this->assertSame([$third->id, $first->id, $second->id], $this->columnOrder());
        $this->assertSame([0, 1, 2], Ticket::whereKey([$third->id, $first->id, $second->id])
            ->orderBy('order')->pluck('order')->all());
    }

    public function test_moving_a_card_to_the_middle_puts_it_before_the_card_it_displaces(): void
    {
        [$first, $second, $third] = $this->cards(3)->all();

        $this->drag($first, 1);

        $this->assertSame([$second->id, $first->id, $third->id], $this->columnOrder());
    }

    public function test_an_index_past_the_end_lands_the_card_last(): void
    {
        [$first, $second, $third] = $this->cards(3)->all();

        $this->drag($first, 99);

        $this->assertSame([$second->id, $third->id, $first->id], $this->columnOrder());
    }

    public function test_the_board_renders_the_cards_in_the_stored_order(): void
    {
        [$first, $second, $third] = $this->cards(3)->all();

        $this->drag($third, 0);

        $records = Livewire::test(Kanban::class, ['project' => $this->project])
            ->instance()
            ->getRecords();

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            $records->pluck('id')->all(),
            'the board must read the order it just wrote'
        );
    }

    public function test_moving_a_card_to_another_column_renumbers_both(): void
    {
        $doing = TicketStatus::factory()->create(['project_id' => null]);
        [$first, $second, $third] = $this->cards(3)->all();
        [$other] = $this->cards(1, $doing)->all();

        $this->drag($second, 0, $doing);

        $this->assertSame([$first->id, $third->id], $this->columnOrder());
        $this->assertSame([0, 1], Ticket::whereKey([$first->id, $third->id])
            ->orderBy('order')->pluck('order')->all());
        $this->assertSame([$second->id, $other->id], $this->columnOrder($doing));
    }

    public function test_a_card_hidden_by_the_filter_bar_keeps_its_place(): void
    {
        $someoneElse = User::factory()->create();
        $this->project->users()->attach($someoneElse->id, ['role' => 'member']);
        [$first, $second, $third] = $this->cards(3)->all();
        // Only $second belongs to the other user, so filtering on them hides
        // the first and third cards from the board.
        $second->update(['owner_id' => $someoneElse->id]);

        Livewire::test(Kanban::class, ['project' => $this->project])
            ->set('users', [$this->user->id])
            ->call('recordUpdated', $third->id, 0, $this->todo->id)
            ->assertSuccessful();

        // $third jumps in front of $first, the card it displaced; $second is
        // untouched between them rather than being pushed to the end.
        $this->assertSame([$third->id, $first->id, $second->id], $this->columnOrder());
    }

    public function test_renumbering_does_not_log_an_activity_for_untouched_cards(): void
    {
        [$first, $second, $third] = $this->cards(3)->all();

        $this->drag($third, 0);

        $this->assertSame(0, TicketActivity::count(), 'reordering is not a status change');
    }
}

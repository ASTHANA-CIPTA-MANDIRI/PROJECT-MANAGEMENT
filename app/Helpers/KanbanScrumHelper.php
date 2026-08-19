<?php

namespace App\Helpers;

use App\Filament\Pages\Forms\BoardFilterForm;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

trait KanbanScrumHelper
{
    public bool $sortable = true;

    public ?Project $project = null;

    public $users = [];

    public $types = [];

    public $priorities = [];

    public $labels = [];

    public $includeNotAffectedTickets = false;

    public bool $ticket = false;

    /**
     * Livewire listeners. Besides the board's own events, subscribe to the
     * project's private broadcast channel so the board updates live when a
     * ticket's status changes or a comment is posted from anywhere.
     *
     * The echo-* listeners bind only when Laravel Echo is available in the
     * browser; with real-time disabled (empty Pusher key) window.Echo is never
     * created, so they simply never fire and the board behaves as before.
     *
     * @return array<int|string, string>
     */
    public function getListeners(): array
    {
        $listeners = [
            'recordUpdated',
            'closeTicketDialog',
        ];

        if ($this->project) {
            $channel = "echo-private:project.{$this->project->id}";
            $listeners["{$channel},.ticket.status.changed"] = 'refreshBoard';
            $listeners["{$channel},.ticket.comment.posted"] = 'refreshBoard';
        }

        return $listeners;
    }

    /**
     * Handle a live broadcast. The board re-queries via getRecords() on every
     * Livewire re-render, so simply handling the event refreshes the columns.
     */
    public function refreshBoard(): void
    {
        //
    }

    protected function formSchema(): array
    {
        return BoardFilterForm::schema($this->project);
    }

    /**
     * The statuses that make up the columns of the board being viewed: the
     * project's own set when it configures custom statuses, the shared global
     * set otherwise.
     */
    protected function statusesQuery(): Builder
    {
        $query = TicketStatus::query();
        if ($this->project && $this->project->status_type === 'custom') {
            $query->where('project_id', $this->project->id);
        } else {
            $query->whereNull('project_id');
        }

        return $query;
    }

    public function getStatuses(): Collection
    {
        $statuses = $this->statusesQuery()->orderBy('order')->get();

        // One grouped COUNT instead of one query per status.
        $ticketCounts = Ticket::query()
            ->when($this->project, fn ($q) => $q->where('project_id', $this->project->id))
            ->whereIn('status_id', $statuses->pluck('id'))
            ->groupBy('status_id')
            ->selectRaw('status_id, count(*) as aggregate')
            ->pluck('aggregate', 'status_id');

        return $statuses->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->name,
            'color' => $item->color,
            'size' => (int) ($ticketCounts[$item->id] ?? 0),
            'add_ticket' => $item->is_default && auth()->user()->can('Create ticket'),
        ]);
    }

    /**
     * The cards this board is made of, before the filter bar narrows them
     * down: the project's tickets, and on a scrum board only those of the
     * current sprint. Renumbering after a drag works on this set, so cards
     * hidden by a filter keep their place instead of being shuffled away.
     *
     * @return Builder<Ticket>
     */
    protected function boardTicketsQuery(): Builder
    {
        return Ticket::query()
            ->where('project_id', $this->project->id)
            ->when(
                $this->project->type === 'scrum',
                fn (Builder $query) => $query->where('sprint_id', $this->project->currentSprint?->id)
            );
    }

    /**
     * The cards actually shown: the board's tickets, narrowed by the filter
     * bar and by what the viewer is allowed to see.
     *
     * @return Builder<Ticket>
     */
    protected function recordsQuery(): Builder
    {
        $query = $this->boardTicketsQuery();
        if (count($this->users)) {
            $query->where(function ($query) {
                return $query->whereIn('owner_id', $this->users)
                    ->orWhereIn('responsible_id', $this->users);
            });
        }
        if (count($this->types)) {
            $query->whereIn('type_id', $this->types);
        }
        if (count($this->priorities)) {
            $query->whereIn('priority_id', $this->priorities);
        }
        if (count($this->labels)) {
            $query->whereHas('labels', fn ($q) => $q->whereIn('labels.id', $this->labels));
        }
        if ($this->includeNotAffectedTickets) {
            $query->whereNull('responsible_id');
        }
        $query->where(function ($query) {
            return $query->where('owner_id', auth()->user()->id)
                ->orWhere('responsible_id', auth()->user()->id)
                ->orWhereHas('project', fn ($query) => $query->accessibleBy(auth()->user()));
        });

        return $query;
    }

    public function getRecords(): Collection
    {
        $query = $this->recordsQuery()
            // relations.relation because the card links to the related
            // ticket's code, and the logged hours are summed in SQL rather
            // than by pulling every hour row of every card into memory. This
            // runs again on every Livewire interaction and every broadcast,
            // so a query per card is a query per card per keystroke.
            ->with([
                'project', 'owner', 'responsible', 'status', 'type', 'priority', 'epic', 'labels',
                'relations', 'relations.relation:id,code',
            ])
            ->withSum('hours', 'value');

        // The board is a sorted board: without this the dragged order was
        // written to the database and then never read back.
        $this->applyBoardOrder($query);

        return $query->get()
            ->map(fn (Ticket $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'title' => $item->name,
                'owner' => $item->owner,
                'type' => $item->type,
                'responsible' => $item->responsible,
                'project' => $item->project,
                'status' => $item->status->id,
                'priority' => $item->priority,
                'epic' => $item->epic,
                'relations' => $item->relations,
                'labels' => $item->labels,
                'due_date' => $item->due_date,
                'is_overdue' => $item->isOverdue,
                'totalLoggedHours' => $item->totalLoggedSeconds ? $item->totalLoggedHours : null,
            ]);
    }

    /**
     * Resolve a ticket the current user is really allowed to drag on this
     * board.
     *
     * $record comes straight from the browser, so the lookup is scoped to the
     * cards this board actually shows - the project's tickets, and on a scrum
     * board only those in the current sprint (see getRecords()) - before the
     * ticket policy has the final say.
     */
    protected function authorizedBoardTicket(int $record): ?Ticket
    {
        if (! $this->project) {
            return null;
        }

        $ticket = $this->boardTicketsQuery()->whereKey($record)->first();

        return $ticket && auth()->user()->can('update', $ticket) ? $ticket : null;
    }

    /**
     * Cards are laid out by their order column, oldest first on a tie — the
     * same sort the renumbering below produces.
     */
    protected function applyBoardOrder(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }

    /**
     * Renumber one status column 0..n so a drag survives a page reload.
     *
     * The browser reports the position among the cards it shows, so the card
     * being displaced is used as the anchor: the moved ticket is slotted in
     * front of it in the full column, leaving tickets hidden by the filter bar
     * where they were. Renumbering goes through the query builder on purpose —
     * it is bookkeeping, not a ticket change worth an activity or a
     * notification.
     */
    protected function reindexStatusColumn(int $statusId, ?int $movedId = null, int $movedIndex = 0): void
    {
        $current = $this->applyBoardOrder(
            $this->boardTicketsQuery()
                ->where('status_id', $statusId)
                ->when($movedId, fn (Builder $query) => $query->whereKeyNot($movedId))
        )->pluck('order', 'id')->all();

        $ids = array_keys($current);

        if ($movedId) {
            $anchorId = $this->applyBoardOrder(
                $this->recordsQuery()
                    ->where('status_id', $statusId)
                    ->whereKeyNot($movedId)
            )->pluck('id')->get($movedIndex);

            $anchorPosition = $anchorId === null ? false : array_search($anchorId, $ids, true);
            array_splice($ids, $anchorPosition === false ? count($ids) : $anchorPosition, 0, [$movedId]);
        }

        foreach ($ids as $position => $id) {
            // Only the cards whose position actually moved are written; a drag
            // near the bottom of a long column then costs a couple of updates
            // rather than one per card.
            if (($current[$id] ?? null) !== $position) {
                Ticket::whereKey($id)->update(['order' => $position]);
            }
        }
    }

    public function recordUpdated(int $record, int $newIndex, int $newStatus): void
    {
        $ticket = $this->authorizedBoardTicket($record);

        // The target column has to be one of this board's own statuses too,
        // otherwise a tampered event could park a ticket on a status it can
        // never be shown in again.
        if (! $ticket || ! $this->statusesQuery()->whereKey($newStatus)->exists()) {
            Filament::notify('danger', __('You are not allowed to move this ticket'));

            return;
        }

        // Atomic: the ticket update, the status-change activity it triggers
        // (Ticket::updating) and the renumbering of the columns it leaves and
        // joins all commit together.
        DB::transaction(function () use ($ticket, $newIndex, $newStatus) {
            $oldStatus = (int) $ticket->status_id;

            $ticket->status_id = $newStatus;
            $ticket->save();

            $this->reindexStatusColumn($newStatus, $ticket->id, $newIndex);
            if ($oldStatus !== $newStatus) {
                $this->reindexStatusColumn($oldStatus);
            }
        });
        Filament::notify('success', __('Ticket updated'));
    }

    public function isMultiProject(): bool
    {
        return $this->project === null;
    }

    public function filter(): void
    {
        $this->getRecords();
    }

    public function resetFilters(): void
    {
        $this->form->fill();
        $this->filter();
    }

    public function createTicket(): void
    {
        $this->ticket = true;
    }

    public function closeTicketDialog(bool $refresh): void
    {
        $this->ticket = false;
        if ($refresh) {
            $this->filter();
        }
    }

    protected function kanbanHeading(): string|Htmlable
    {
        return $this->boardHeading(__('Kanban'));
    }

    protected function scrumHeading(): string|Htmlable
    {
        return $this->boardHeading(__('Scrum'));
    }

    /**
     * Rendered through Blade so the project name — free-form user input — is
     * escaped by default instead of being concatenated into raw HTML.
     */
    private function boardHeading(string $title): Htmlable
    {
        return new HtmlString(
            view('components.board-heading', [
                'title' => $title,
                'projectName' => $this->project?->name,
            ])->render()
        );
    }

    protected function scrumSubHeading(): string|Htmlable|null
    {
        if (! $this->project?->currentSprint) {
            return null;
        }

        return new HtmlString(
            view('components.board-subheading', [
                'sprint' => $this->project->currentSprint,
                'nextSprint' => $this->project->nextSprint,
            ])->render()
        );
    }
}

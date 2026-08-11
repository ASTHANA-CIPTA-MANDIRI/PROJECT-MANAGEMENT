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
        return BoardFilterForm::schema();
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

    public function getRecords(): Collection
    {
        $query = Ticket::query();
        if ($this->project->type === 'scrum') {
            $query->where('sprint_id', $this->project->currentSprint->id);
        }
        $query->with(['project', 'owner', 'responsible', 'status', 'type', 'priority', 'epic', 'labels', 'relations']);
        $query->where('project_id', $this->project->id);
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

        $ticket = $this->project->tickets()
            ->when(
                $this->project->type === 'scrum',
                fn ($query) => $query->where('sprint_id', $this->project->currentSprint?->id)
            )
            ->whereKey($record)
            ->first();

        return $ticket && auth()->user()->can('update', $ticket) ? $ticket : null;
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

        // Atomic: the ticket update and the status-change activity it
        // triggers (Ticket::updating) commit together.
        DB::transaction(function () use ($ticket, $newIndex, $newStatus) {
            $ticket->order = $newIndex;
            $ticket->status_id = $newStatus;
            $ticket->save();
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

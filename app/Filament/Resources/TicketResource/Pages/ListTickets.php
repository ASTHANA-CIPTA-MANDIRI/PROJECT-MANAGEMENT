<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\TicketStatus;
use App\Support\Colors;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /** How many status cards are shown next to "Total". */
    private const STATUS_CARDS = 4;

    /** Tabs above the table (key => label); the key is the only client input. */
    private const TABS = ['all', 'mine', 'unassigned', 'overdue', 'byMe'];

    /** Active tab key. Server side only constrains the already-scoped base query. */
    public string $activeTab = 'all';

    /**
     * The status cards sit at the top of the table card itself (one canvas:
     * cards, toolbar, table). Rendered by the table component inside this
     * Livewire page, so the cards' wire:click reaches applyStatusCard().
     */
    protected function getTableHeader(): ?View
    {
        return view('filament.resources.tickets.status-cards', [
            'cards' => $this->statusCards(),
            'tabs' => $this->tabs(),
        ]);
    }

    /**
     * Tab constraint on top of the resource's own access-scoped query
     * (Organization + Project + Policy visibility, soft deletes): it can only
     * narrow it, never widen it. Search, filters and the status cards then
     * apply on top as usual.
     */
    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getTableQuery();
        $userId = auth()->id();

        return match ($this->activeTab) {
            'mine' => $query->where('tickets.responsible_id', $userId),
            'unassigned' => $query->whereNull('tickets.responsible_id'),
            'byMe' => $query->where('tickets.owner_id', $userId),
            'overdue' => $query->whereDate('tickets.due_date', '<', now()->startOfDay())
                ->whereDoesntHave('status', fn ($status) => $status->where('is_final', true)),
            default => $query,
        };
    }

    /** Tab click. Unknown keys fall back to "all"; the page and selection reset. */
    public function selectTab(string $tab): void
    {
        $this->activeTab = in_array($tab, self::TABS, true) ? $tab : 'all';

        $this->deselectAllTableRecords();
        $this->resetPage();
    }

    /**
     * Tab counts in ONE aggregate query over the same access-scoped base query
     * the table uses (non-deleted tickets). "Overdue" = due before today and
     * the status is not a final one.
     *
     * @return array<int, array{key: string, label: string, count: int, active: bool, danger: bool}>
     */
    private function tabs(): array
    {
        $userId = (int) auth()->id();

        $row = TicketResource::getEloquentQuery()
            ->whereNull('tickets.deleted_at')
            ->reorder()
            ->toBase()
            ->selectRaw(
                'count(*) as total,
                 coalesce(sum(case when tickets.responsible_id = ? then 1 else 0 end), 0) as mine,
                 coalesce(sum(case when tickets.responsible_id is null then 1 else 0 end), 0) as unassigned,
                 coalesce(sum(case when tickets.owner_id = ? then 1 else 0 end), 0) as by_me,
                 coalesce(sum(case when tickets.due_date is not null and date(tickets.due_date) < ?
                     and not exists (select 1 from ticket_statuses ts where ts.id = tickets.status_id and ts.is_final = ?)
                     then 1 else 0 end), 0) as overdue',
                [$userId, $userId, now()->startOfDay()->toDateString(), 1],
            )
            ->first();

        $counts = [
            'all' => (int) ($row->total ?? 0),
            'mine' => (int) ($row->mine ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'byMe' => (int) ($row->by_me ?? 0),
        ];

        $labels = [
            'all' => __('All'),
            'mine' => __('My tickets'),
            'unassigned' => __('Unassigned'),
            'overdue' => __('Overdue'),
            'byMe' => __('Assigned by me'),
        ];

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $labels[$key],
            'count' => $counts[$key],
            'active' => $this->activeTab === $key,
            'danger' => $key === 'overdue',
        ], self::TABS);
    }

    /**
     * The cards and tabs are recomputed from the live, access-scoped query on
     * every render, but a render only happens on a Livewire interaction. Poll
     * the table so counts also follow changes made elsewhere (another tab,
     * another user, a queue job). Filament keeps filters, search, sorting,
     * pagination and the active tab/card in component state across polls.
     */
    protected function getTablePollingInterval(): ?string
    {
        return '15s';
    }

    protected function shouldPersistTableFiltersInSession(): bool
    {
        return true;
    }

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableActionsColumnLabel(): ?string
    {
        return __('Actions');
    }

    /**
     * Status groups visible to the current user, busiest first. Counts come
     * from TicketResource::getEloquentQuery() (the same access-scoped base
     * query as the table: Organization + Project + Policy visibility) limited
     * to non-deleted tickets, which is what the table shows by default.
     * Statuses are dynamic and may repeat per project, so they are grouped by
     * (case-insensitive) name; the group carries every matching status id.
     *
     * @return array<string, array{key: string, label: string, color: string, count: int, ids: array<int, int>, order: int}>
     */
    private function statusGroups(): array
    {
        $counts = TicketResource::getEloquentQuery()
            ->whereNull('tickets.deleted_at')
            ->reorder()
            ->selectRaw('tickets.status_id, count(*) as aggregate')
            ->groupBy('tickets.status_id')
            ->pluck('aggregate', 'status_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $groups = [];

        TicketStatus::withTrashed()->whereIn('id', $counts->keys())->orderBy('order')->get()
            ->each(function (TicketStatus $status) use ($counts, &$groups): void {
                $key = mb_strtolower($status->name);
                $color = preg_match(Colors::HEX_PATTERN, (string) $status->color) ? $status->color : Colors::DEFAULT;

                $groups[$key] ??= ['key' => $key, 'label' => $status->name, 'color' => $color, 'count' => 0, 'ids' => [], 'order' => (int) $status->order];
                $groups[$key]['count'] += (int) $counts[$status->id];
                $groups[$key]['ids'][] = $status->id;
            });

        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }

    /**
     * Icon + colour per recognisable status name (heroicon-o-*); an unknown
     * status keeps a neutral icon and its own colour from the data.
     *
     * @return array{icon: string, color: string|null}
     */
    private function cardStyle(string $name): array
    {
        $name = mb_strtolower($name);

        return match (true) {
            str_contains($name, 'progress'), str_contains($name, 'doing'), str_contains($name, 'proses') => ['icon' => 'heroicon-o-refresh', 'color' => '#f97316'],
            str_contains($name, 'review') => ['icon' => 'heroicon-o-eye', 'color' => '#8b5cf6'],
            str_contains($name, 'done'), str_contains($name, 'complete'), str_contains($name, 'closed'), str_contains($name, 'selesai') => ['icon' => 'heroicon-o-check-circle', 'color' => '#16a34a'],
            str_contains($name, 'todo'), str_contains($name, 'open'), str_contains($name, 'pending'), str_contains($name, 'backlog') => ['icon' => 'heroicon-o-clock', 'color' => '#2563eb'],
            default => ['icon' => 'heroicon-o-tag', 'color' => null],
        };
    }

    /**
     * @return array<int, array{key: string|null, label: string, color: string, icon: string, count: int, percent: int, active: bool}>
     */
    private function statusCards(): array
    {
        $groups = $this->statusGroups();
        $selected = collect($this->tableFilters['status_id']['values'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $total = array_sum(array_column($groups, 'count'));

        $cards = [[
            'key' => null,
            'label' => __('Total'),
            'color' => '#2563eb',
            'icon' => 'heroicon-o-ticket',
            'count' => $total,
            'percent' => $total > 0 ? 100 : 0,
            'active' => $selected === [],
        ]];

        // The busiest statuses get a card, shown in the workflow (status
        // `order`) sequence so the cards do not shuffle when counts change.
        $shown = array_slice($groups, 0, self::STATUS_CARDS);
        usort($shown, fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        foreach ($shown as $group) {
            $ids = collect($group['ids'])->sort()->values()->all();
            $style = $this->cardStyle($group['label']);

            $cards[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'color' => $style['color'] ?? $group['color'],
                'icon' => $style['icon'],
                'count' => $group['count'],
                'percent' => $total > 0 ? min(100, (int) round($group['count'] / $total * 100)) : 0,
                'active' => $selected !== [] && $selected === $ids,
            ];
        }

        return $cards;
    }

    /**
     * Card click: filter the table by that status group through the table's
     * own `status_id` filter (so search, sorting and pagination stay server
     * side and consistent). The name is re-resolved against the user's
     * access-scoped groups - a client-sent value is never trusted as an id.
     * Clicking the active card, or "Total" (null), clears the status filter.
     */
    public function applyStatusCard(?string $key = null): void
    {
        $group = $key !== null ? ($this->statusGroups()[$key] ?? null) : null;
        $current = collect($this->tableFilters['status_id']['values'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $target = $group ? collect($group['ids'])->sort()->values()->all() : [];

        $this->tableFilters['status_id'] = [
            'values' => ($group && $current !== $target) ? array_map('strval', $group['ids']) : [],
        ];

        $this->updatedTableFilters();
    }
}

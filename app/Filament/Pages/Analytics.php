<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\TicketActivity;
use App\Services\Analytics\BurndownReport;
use App\Services\Analytics\CompletionResolver;
use App\Services\Analytics\ResourceUtilizationReport;
use App\Services\Analytics\TimelineForecast;
use App\Services\Analytics\VelocityReport;
use App\Support\Colors;
use Illuminate\Support\Collection;

/**
 * Advanced reporting: team velocity, sprint burn-down, resource utilization and
 * a timeline forecast for a selected project.
 */
class Analytics extends AuthorizedPage
{
    protected static ?string $permission = 'View analytics';

    protected static ?string $navigationIcon = 'heroicon-o-chart-square-bar';

    protected static ?string $slug = 'analytics';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.analytics';

    /** Currently selected project. */
    public ?int $projectId = null;

    /** Currently selected sprint for the burn-down (defaults to the latest). */
    public ?int $sprintId = null;

    /**
     * Memoized currentProject() result. Not a Livewire property on purpose:
     * it is rebuilt from scratch on every request, which is exactly the
     * lifetime we want.
     */
    private ?Project $resolvedProject = null;

    private bool $projectResolved = false;

    /** Memoized insights() result, rebuilt on every request. */
    private ?array $insightsMemo = null;

    protected static function getNavigationLabel(): string
    {
        return __('Analytics');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Management');
    }

    public function mount(): void
    {
        $this->projectId = $this->accessibleProjects()->keys()->first();
        $this->sprintId = $this->sprintOptions()->keys()->last();
    }

    public function updatedProjectId(): void
    {
        // A different project than whatever was memoized before.
        $this->projectResolved = false;
        $this->resolvedProject = null;
        $this->insightsMemo = null;

        // $projectId is a public Livewire property, so the client can post any
        // id it likes. Drop anything the user cannot reach instead of leaving
        // it selected.
        if (! $this->currentProject()) {
            $this->projectId = null;
        }

        // When the project changes, reset the burn-down to its latest sprint.
        $this->sprintId = $this->sprintOptions()->keys()->last();
    }

    /**
     * Projects the current user owns or belongs to, as [id => name].
     */
    public function accessibleProjects(): Collection
    {
        $user = auth()->user();

        return Project::query()
            ->accessibleBy($user)
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    public function sprintOptions(): Collection
    {
        if (! $this->currentProject()) {
            return collect();
        }

        return $this->currentProject()->sprints()
            ->orderBy('starts_at')
            ->pluck('name', 'id');
    }

    /**
     * The selected project, but only if the current user may see it. Every
     * report below goes through here, so this is the single place that decides
     * whose data the page is allowed to render.
     *
     * Memoized per request: velocity(), burndown(), utilization(), forecast()
     * and sprintOptions() all call this, so one render otherwise repeats the
     * same access-scoped lookup half a dozen times. updatedProjectId() clears
     * the memo, so a changed selection is always resolved again - and with it
     * re-checked against the access scope.
     */
    public function currentProject(): ?Project
    {
        if ($this->projectResolved) {
            return $this->resolvedProject;
        }

        $this->projectResolved = true;

        return $this->resolvedProject = $this->projectId
            ? Project::accessibleBy(auth()->user())->whereKey($this->projectId)->first()
            : null;
    }

    // --------------------------------------------------------- report data

    /**
     * Count-based insights for any project type (no points / weighting
     * involved): weekly created-vs-resolved trend, time to resolve, open
     * tickets by priority and type, workload, stale tickets, epic progress
     * and tickets per sprint. One ticket query for the (access-scoped)
     * current project plus one for the status-change history of its
     * finished tickets; everything else is derived in memory. "Done" is the
     * same definition the velocity report uses (CompletionResolver). A
     * finished ticket's completion date is its last move into a final
     * status, falling back to updated_at when it has no history row (e.g.
     * seeded or imported straight into Done).
     *
     * @return array<string, mixed>
     */
    public function insights(): array
    {
        if ($this->insightsMemo !== null) {
            return $this->insightsMemo;
        }

        $empty = [
            'has_data' => false,
            'trend' => [],
            'cycle' => ['count' => 0, 'avg_days' => null, 'median_days' => null, 'by_type' => []],
            'priority' => [],
            'type' => [],
            'workload' => [],
            'stale' => [],
            'epics' => [],
            'sprints' => [],
        ];

        if (! $project = $this->currentProject()) {
            return $this->insightsMemo = $empty;
        }

        $doneIds = CompletionResolver::completedStatusIds($project);
        $now = now();

        $tickets = $project->tickets()
            ->with(['responsible:id,name', 'type:id,name,color', 'priority:id,name,color'])
            ->get(['id', 'code', 'name', 'status_id', 'responsible_id', 'epic_id', 'sprint_id', 'type_id', 'priority_id', 'created_at', 'updated_at']);

        if ($tickets->isEmpty()) {
            return $this->insightsMemo = $empty;
        }

        $isDone = fn ($ticket) => in_array($ticket->status_id, $doneIds, true);
        $done = $tickets->filter($isDone);
        $open = $tickets->reject($isDone);

        // Completion date per finished ticket.
        $lastDoneMove = TicketActivity::query()
            ->whereIn('ticket_id', $done->pluck('id'))
            ->whereIn('new_status_id', $doneIds)
            ->get(['ticket_id', 'created_at'])
            ->groupBy('ticket_id')
            ->map(fn (Collection $rows) => $rows->max('created_at'));
        $completedAt = fn ($ticket) => $lastDoneMove->get($ticket->id) ?? $ticket->updated_at;

        // Weekly trend (last 8 weeks, Monday-based).
        $firstWeek = $now->copy()->startOfWeek()->subWeeks(7);
        $trend = collect(range(0, 7))->map(function (int $i) use ($firstWeek, $tickets, $done, $completedAt) {
            $start = $firstWeek->copy()->addWeeks($i);
            $end = $start->copy()->addWeek();

            return [
                'label' => $start->translatedFormat('d M'),
                'created' => $tickets->filter(fn ($t) => $t->created_at >= $start && $t->created_at < $end)->count(),
                'resolved' => $done->filter(fn ($t) => $completedAt($t) >= $start && $completedAt($t) < $end)->count(),
            ];
        })->all();

        // Time to resolve: tickets finished in the last 90 days.
        $recent = $done->filter(fn ($t) => $completedAt($t) >= $now->copy()->subDays(90));
        $days = $recent->map(fn ($t) => max(0, round($t->created_at->diffInHours($completedAt($t)) / 24, 1)))->values();
        $median = null;
        if ($days->isNotEmpty()) {
            $sorted = $days->sort()->values();
            $mid = intdiv($sorted->count(), 2);
            $median = $sorted->count() % 2 ? $sorted[$mid] : round(($sorted[$mid - 1] + $sorted[$mid]) / 2, 1);
        }
        $byType = $recent->groupBy('type_id')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->type?->name ?? '-',
                'color' => Colors::safe($group->first()->type?->color),
                'avg_days' => round($group->avg(fn ($t) => max(0, $t->created_at->diffInHours($completedAt($t)) / 24)), 1),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')->take(6)->values()->all();

        $breakdown = fn (string $relation) => $open->groupBy($relation.'_id')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->{$relation}?->name ?? '-',
                'color' => Colors::safe($group->first()->{$relation}?->color),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')->values()->all();

        // Workload: open tickets per assignee + finished in the last 30 days.
        $since = $now->copy()->subDays(30);
        $workload = $tickets
            ->groupBy(fn ($t) => $t->responsible_id ?? 0)
            ->map(function (Collection $group) use ($isDone, $completedAt, $since) {
                return [
                    'name' => $group->first()->responsible?->name ?? __('Unassigned'),
                    'open' => $group->reject($isDone)->count(),
                    'done_30d' => $group->filter($isDone)->filter(fn ($t) => $completedAt($t) >= $since)->count(),
                ];
            })
            ->filter(fn (array $row) => $row['open'] + $row['done_30d'] > 0)
            ->sortByDesc('open')->take(8)->values()->all();

        // Stale: open tickets untouched for two weeks or more.
        $stale = $open
            ->filter(fn ($t) => $t->updated_at->lte($now->copy()->subDays(14)))
            ->sortBy('updated_at')
            ->take(8)
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'assignee' => $t->responsible?->name,
                'days' => (int) $t->updated_at->diffInDays($now),
            ])->values()->all();

        $ticketsByEpic = $tickets->whereNotNull('epic_id')->groupBy('epic_id');
        $epics = $project->epics()->get(['id', 'name'])
            ->map(function ($epic) use ($ticketsByEpic, $isDone) {
                $group = $ticketsByEpic->get($epic->id, collect());
                $epicDone = $group->filter($isDone)->count();

                return [
                    'name' => $epic->name,
                    'total' => $group->count(),
                    'done' => $epicDone,
                    'pct' => $group->count() ? round($epicDone / $group->count() * 100) : 0,
                ];
            })
            ->filter(fn (array $epic) => $epic['total'] > 0)
            ->sortByDesc('total')->take(8)->values()->all();

        $ticketsBySprint = $tickets->whereNotNull('sprint_id')->groupBy('sprint_id');
        $sprints = $project->sprints()->orderBy('starts_at')->get(['id', 'name'])
            ->map(function ($sprint) use ($ticketsBySprint, $isDone) {
                $group = $ticketsBySprint->get($sprint->id, collect());

                return ['name' => $sprint->name, 'total' => $group->count(), 'done' => $group->filter($isDone)->count()];
            })
            ->filter(fn (array $sprint) => $sprint['total'] > 0)
            ->values()->all();

        return $this->insightsMemo = [
            'has_data' => true,
            'trend' => $trend,
            'cycle' => [
                'count' => $recent->count(),
                'avg_days' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
                'median_days' => $median,
                'by_type' => $byType,
            ],
            'priority' => $breakdown('priority'),
            'type' => $breakdown('type'),
            'workload' => $workload,
            'stale' => $stale,
            'epics' => $epics,
            'sprints' => $sprints,
        ];
    }

    public function velocity(): array
    {
        if (! $project = $this->currentProject()) {
            return [];
        }

        return (new VelocityReport($project))->perSprint()->all();
    }

    public function burndown(): array
    {
        $project = $this->currentProject();
        $sprint = $this->sprintId ? $project?->sprints()->find($this->sprintId) : null;

        return $sprint ? (new BurndownReport($sprint))->data()
            : ['total' => 0, 'labels' => [], 'ideal' => [], 'remaining' => []];
    }

    public function utilization(): Collection
    {
        if (! $project = $this->currentProject()) {
            return collect();
        }

        return (new ResourceUtilizationReport(
            now()->subDays(30)->startOfDay(),
            now()->endOfDay(),
            $project
        ))->perUser();
    }

    public function forecast(): array
    {
        if (! $project = $this->currentProject()) {
            return ['confident' => false, 'remaining_points' => 0, 'avg_velocity' => 0, 'sprints_remaining' => null, 'forecast_date' => null];
        }

        return (new TimelineForecast($project))->forecast();
    }
}

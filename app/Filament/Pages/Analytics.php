<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Services\Analytics\BurndownReport;
use App\Services\Analytics\ResourceUtilizationReport;
use App\Services\Analytics\TimelineForecast;
use App\Services\Analytics\VelocityReport;
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

    public function currentProject(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    // --------------------------------------------------------- report data

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

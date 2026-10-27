<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Timesheet;

use App\Models\TicketHour;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\DoughnutChartWidget;
use Illuminate\Support\Collection;

class ActivitiesReport extends DoughnutChartWidget
{
    use HasYearFilter;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    public ?string $filter = null;

    public function __construct($id = null)
    {
        $this->filter = (string) Carbon::now()->year;

        parent::__construct($id);
    }

    // Without a max height Chart.js sizes the canvas from its 300x150 default.
    protected static ?string $maxHeight = '300px';

    protected static string $view = 'filament.widgets.timesheet-chart';

    protected static ?array $options = [
        'maintainAspectRatio' => false,
        'cutout' => '66%',
        'plugins' => [
            'legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'padding' => 16, 'color' => '#6f767e']],
            'tooltip' => ['backgroundColor' => '#1f2937', 'padding' => 10, 'cornerRadius' => 10],
        ],
        'animation' => ['animateRotate' => true, 'duration' => 900],
    ];

    /**
     * One hue, light to dark (the dashboard's single-colour rule), repeated
     * when there are more activities than shades.
     *
     * @return array<int, string>
     */
    private function palette(int $count): array
    {
        $shades = ['#3b82d6', '#6aa3e3', '#2f6fbd', '#9cc2ee', '#245a9c', '#c2dbf6', '#6f767e', '#a9aeb4'];

        return array_map(fn (int $i) => $shades[$i % count($shades)], range(0, max(0, $count - 1)));
    }

    protected function getHeading(): string
    {
        return __('Logged time by activity');
    }

    protected function getFilters(): ?array
    {
        return $this->yearFilterOptions();
    }

    protected function getData(): array
    {
        $collection = $this->filter(auth()->user(), $this->filter);

        $datasets = $this->getDatasets($collection);

        return [
            'datasets' => [
                [
                    'label' => __('Total time logged'),
                    'data' => $datasets['sets'],
                    'backgroundColor' => $this->palette(count($datasets['sets'])),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 3,
                    'hoverOffset' => 8,
                ],
            ],
            'labels' => $datasets['labels'],
        ];
    }

    /**
     * @param  Collection<int, object{value: float, activity: ?\App\Models\Activity}>  $collection
     */
    protected function getDatasets(Collection $collection): array
    {
        $datasets = [
            'sets' => [],
            'labels' => [],
        ];

        foreach ($collection as $item) {
            $datasets['sets'][] = $item->value;
            $datasets['labels'][] = $item->activity?->name ?? __('No activity');
        }

        return $datasets;
    }

    /**
     * Total logged hours per activity for the user and year. Grouping happens
     * in PHP so it runs on any database driver.
     *
     * @return Collection<int, object{value: float, activity: ?\App\Models\Activity}>
     */
    protected function filter(User $user, ?string $year): Collection
    {
        $year = (int) ($year ?: Carbon::now()->year);

        return TicketHour::with('activity')
            ->unlessOrganizationLocked($user)
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [
                Carbon::create($year, 1, 1)->startOfDay(),
                Carbon::create($year, 12, 31)->endOfDay(),
            ])
            ->get()
            ->groupBy('activity_id')
            ->map(fn (Collection $group) => (object) [
                'value' => (float) $group->sum('value'),
                'activity' => $group->first()->activity,
            ])
            ->values();
    }
}

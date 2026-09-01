<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Timesheet;

use App\Models\TicketHour;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\BarChartWidget;
use Illuminate\Support\Collection;

class ActivitiesReport extends BarChartWidget
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
                    'backgroundColor' => [
                        'rgba(54, 162, 235, .6)',
                    ],
                    'borderColor' => [
                        'rgba(54, 162, 235, .8)',
                    ],
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

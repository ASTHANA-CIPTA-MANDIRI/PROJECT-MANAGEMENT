<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Timesheet;

use App\Models\TicketHour;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\BarChartWidget;
use Illuminate\Support\Collection;

class MonthlyReport extends BarChartWidget
{
    use HasYearFilter;

    protected function getHeading(): string
    {
        return __('Logged time monthly');
    }

    public ?string $filter = null;

    public function __construct($id = null)
    {
        $this->filter = (string) Carbon::now()->year;

        parent::__construct($id);
    }

    protected function getData(): array
    {
        $collection = $this->filter(auth()->user(), $this->filter);

        $datasets = $this->getDatasets($this->buildRapport($collection));

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

    protected function getFilters(): ?array
    {
        return $this->yearFilterOptions();
    }

    protected static ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => true,
            ],
        ],
    ];

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    /**
     * Total logged hours per month number (1-12) for the user and year.
     * Grouping happens in PHP so it runs on any database driver.
     *
     * @return Collection<int, float>
     */
    protected function filter(User $user, ?string $year): Collection
    {
        $year = (int) ($year ?: Carbon::now()->year);

        return TicketHour::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [
                Carbon::create($year, 1, 1)->startOfDay(),
                Carbon::create($year, 12, 31)->endOfDay(),
            ])
            ->get()
            ->groupBy(fn (TicketHour $hour) => (int) $hour->created_at->format('n'))
            ->map(fn (Collection $group) => (float) $group->sum('value'));
    }

    protected function getDatasets(array $rapportData): array
    {
        $datasets = [
            'sets' => [],
            'labels' => [],
        ];

        foreach ($rapportData as $data) {
            $datasets['sets'][] = $data[1];
            $datasets['labels'][] = $data[0];
        }

        return $datasets;
    }

    /**
     * @param  Collection<int, float>  $collection
     */
    protected function buildRapport(Collection $collection): array
    {
        $months = [
            1 => ['January', 0],
            2 => ['February', 0],
            3 => ['March', 0],
            4 => ['April', 0],
            5 => ['May', 0],
            6 => ['June', 0],
            7 => ['July', 0],
            8 => ['August', 0],
            9 => ['September', 0],
            10 => ['October', 0],
            11 => ['November', 0],
            12 => ['December', 0],
        ];

        foreach ($collection as $month => $value) {
            if (isset($months[$month])) {
                $months[$month][1] = (float) $value;
            }
        }

        return $months;
    }
}

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
                    'label' => __('Weekdays'),
                    // Floating bars ([from, to]) rather than a stacked chart: Chart.js turns off
                    // the corner radius of the lower segment of a stack, which breaks the pills.
                    'grouped' => false,
                    'data' => $datasets['weekday'],
                    'backgroundColor' => '#3b82d6',
                    'hoverBackgroundColor' => '#6aa3e3',
                    'borderColor' => '#ffffff',
                    'borderWidth' => 3,
                    'borderRadius' => 100,
                    'borderSkipped' => false,
                    'maxBarThickness' => 30,
                    'barPercentage' => 0.7,
                    'categoryPercentage' => 0.8,
                ],
                [
                    'label' => __('Weekend'),
                    'grouped' => false,
                    'data' => $datasets['weekend'],
                    'backgroundColor' => '#ee9a82',
                    'hoverBackgroundColor' => '#f3b3a1',
                    'borderColor' => '#ffffff',
                    'borderWidth' => 3,
                    'borderRadius' => 100,
                    'borderSkipped' => false,
                    'maxBarThickness' => 30,
                    'barPercentage' => 0.7,
                    'categoryPercentage' => 0.8,
                ],
            ],
            'labels' => $datasets['labels'],
        ];
    }

    protected function getFilters(): ?array
    {
        return $this->yearFilterOptions();
    }

    // Without a max height Chart.js sizes the canvas from its 300x150 default.
    protected static ?string $maxHeight = '300px';

    protected static string $view = 'filament.widgets.timesheet-chart';

    protected static ?array $options = [
        'maintainAspectRatio' => false,
        'interaction' => ['mode' => 'index', 'intersect' => false],
        'plugins' => [
            'legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'padding' => 18, 'color' => '#6f767e']],
            'tooltip' => [
                'backgroundColor' => '#ffffff',
                'titleColor' => '#1f2937',
                'bodyColor' => '#1f2937',
                'borderColor' => '#e5e7eb',
                'borderWidth' => 1,
                'padding' => 12,
                'cornerRadius' => 12,
                'usePointStyle' => true,
                'boxPadding' => 6,
                'titleFont' => ['weight' => '600'],
                'bodyFont' => ['weight' => '600'],
            ],
            'rkHoverBand' => ['enabled' => true],
        ],
        'scales' => [
            'x' => ['stacked' => false, 'grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['color' => '#9aa0a6', 'font' => ['size' => 13]]],
            'y' => ['stacked' => false, 'beginAtZero' => true, 'grid' => ['color' => 'rgba(148, 163, 184, .22)', 'drawBorder' => false], 'ticks' => ['color' => '#9aa0a6', 'font' => ['size' => 13]]],
        ],
        'animation' => ['duration' => 900, 'easing' => 'easeOutQuart'],
    ];

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 6,
    ];

    /**
     * Logged hours per month number (1-12) for the user and year, split into
     * Monday-Friday and Saturday-Sunday so the two stack to the month's total.
     * Grouping happens in PHP so it runs on any database driver.
     *
     * @return Collection<int, array{weekday: float, weekend: float}>
     */
    protected function filter(User $user, ?string $year): Collection
    {
        $year = (int) ($year ?: Carbon::now()->year);

        return TicketHour::query()
            ->unlessOrganizationLocked($user)
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [
                Carbon::create($year, 1, 1)->startOfDay(),
                Carbon::create($year, 12, 31)->endOfDay(),
            ])
            ->get()
            ->groupBy(fn (TicketHour $hour) => (int) $hour->created_at->format('n'))
            ->map(fn (Collection $group) => [
                'weekday' => (float) $group->filter(fn (TicketHour $hour) => ! $hour->created_at->isWeekend())->sum('value'),
                'weekend' => (float) $group->filter(fn (TicketHour $hour) => $hour->created_at->isWeekend())->sum('value'),
            ]);
    }

    /**
     * @return array{weekday: array<int, ?array{0: float, 1: float}>, weekend: array<int, ?array{0: float, 1: float}>, labels: array<int, string>}
     */
    protected function getDatasets(array $rapportData): array
    {
        $datasets = [
            'weekday' => [],
            'weekend' => [],
            'labels' => [],
        ];

        foreach ($rapportData as $data) {
            [, $weekday, $weekend] = $data;

            $datasets['labels'][] = $data[0];
            // Weekdays from the axis up, weekend starting where weekdays end; an empty part is
            // left out (null) so no zero-height sliver is drawn.
            $datasets['weekday'][] = $weekday > 0 ? [0.0, $weekday] : null;
            $datasets['weekend'][] = $weekend > 0 ? [$weekday, $weekday + $weekend] : null;
        }

        return $datasets;
    }

    /**
     * @param  Collection<int, array{weekday: float, weekend: float}>  $collection
     */
    protected function buildRapport(Collection $collection): array
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[$month] = [Carbon::create(2000, $month, 1)->translatedFormat('F'), 0.0, 0.0];
        }

        foreach ($collection as $month => $value) {
            if (isset($months[$month])) {
                $months[$month][1] = $value['weekday'];
                $months[$month][2] = $value['weekend'];
            }
        }

        return $months;
    }
}

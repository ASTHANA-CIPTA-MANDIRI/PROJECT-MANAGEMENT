<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Timesheet;

use App\Models\TicketHour;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\BarChartWidget;
use Illuminate\Support\Collection;

class WeeklyReport extends BarChartWidget
{
    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    public function __construct($id = null)
    {
        $weekDaysData = $this->getWeekStartAndFinishDays();

        $this->filter = $weekDaysData['weekStartDate'].' - '.$weekDaysData['weekEndDate'];

        parent::__construct($id);
    }

    protected function getHeading(): string
    {
        return __('Weekly logged time');
    }

    protected function getData(): array
    {
        [$weekStart, $weekEnd] = $this->parseWeekRange((string) $this->filter);

        $dates = $this->buildDatesRange($weekStart, $weekEnd);

        $collection = $this->filter(auth()->user(), $weekStart, $weekEnd);

        $datasets = $this->buildRapport($collection, $dates);

        return [
            'datasets' => [
                [
                    'label' => __('Weekly time logged'),
                    'data' => $datasets,
                    'backgroundColor' => [
                        'rgba(54, 162, 235, .6)',
                    ],
                    'borderColor' => [
                        'rgba(54, 162, 235, .8)',
                    ],
                ],
            ],
            'labels' => $dates,
        ];
    }

    protected function getFilters(): ?array
    {
        return $this->yearWeeks();
    }

    protected function buildRapport(Collection $collection, array $dates): array
    {
        $template = $this->createReportTemplate($dates);
        foreach ($collection as $day => $value) {
            if (isset($template[$day])) {
                $template[$day]['value'] = $value;
            }
        }

        return collect($template)->pluck('value')->toArray();
    }

    /**
     * Total logged hours per day for the given user and week, keyed Y-m-d.
     *
     * Grouping happens in PHP (not DATE_FORMAT) so the query runs on any
     * database driver.
     *
     * @return Collection<string, float>
     */
    protected function filter(User $user, string $weekStart, string $weekEnd): Collection
    {
        return TicketHour::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [
                Carbon::parse($weekStart)->startOfDay(),
                Carbon::parse($weekEnd)->endOfDay(),
            ])
            ->get()
            ->groupBy(fn (TicketHour $hour) => $hour->created_at->format('Y-m-d'))
            ->map(fn (Collection $group) => (float) $group->sum('value'));
    }

    /**
     * Every Y-m-d in the (validated) range. Guarded so a malformed or empty
     * filter can never spin CarbonPeriod into an endless loop.
     *
     * @return array<int, string>
     */
    protected function buildDatesRange(string $weekStart, string $weekEnd): array
    {
        if ($weekStart === '' || $weekEnd === '') {
            return [];
        }

        try {
            $start = Carbon::parse($weekStart)->startOfDay();
            $end = Carbon::parse($weekEnd)->startOfDay();
        } catch (\Exception) {
            return [];
        }

        if ($start->greaterThan($end)) {
            return [];
        }

        // A week is 7 days; cap defensively so no range can explode.
        if ($start->diffInDays($end) > 31) {
            $end = $start->copy()->addDays(31);
        }

        $dates = [];
        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Split the "Y-m-d - Y-m-d" filter, tolerating a missing/blank value.
     *
     * @return array{0: string, 1: string}
     */
    protected function parseWeekRange(string $filter): array
    {
        $parts = explode(' - ', $filter);

        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    protected function createReportTemplate(array $dates): array
    {
        $template = [];
        foreach ($dates as $date) {
            $template[$date]['value'] = 0;
        }

        return $template;
    }

    protected function yearWeeks(): array
    {
        $year = date_create('today')->format('Y');

        $dtStart = date_create('2 jan '.$year)->modify('last Monday');
        $dtEnd = date_create('last monday of Dec '.$year);

        for ($weeks = []; $dtStart <= $dtEnd; $dtStart->modify('+1 week')) {
            $from = $dtStart->format('Y-m-d');
            $to = (clone $dtStart)->modify('+6 Days')->format('Y-m-d');
            $weeks[$from.' - '.$to] = $from.' - '.$to;
        }

        return $weeks;
    }

    protected function getWeekStartAndFinishDays(): array
    {
        $now = Carbon::now();

        return [
            'weekStartDate' => $now->startOfWeek()->format('Y-m-d'),
            'weekEndDate' => $now->endOfWeek()->format('Y-m-d'),
        ];
    }
}

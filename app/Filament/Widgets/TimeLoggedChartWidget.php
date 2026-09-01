<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use Filament\Widgets\BarChartWidget;
use Illuminate\Database\Eloquent\Builder;

abstract class TimeLoggedChartWidget extends BarChartWidget
{
    use WithCachedData;

    protected static ?string $heading = 'Chart';

    protected static ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    public static function canView(): bool
    {
        return auth()->user()->can('List tickets');
    }

    /**
     * Query for the records to sum logged hours for (must have an `hours`
     * relation). Implementations must scope it to the current user.
     */
    abstract protected function query(): Builder;

    /** Column selected alongside `id` and used as each bar's label. */
    abstract protected function labelColumn(): string;

    /**
     * Narrow which logged hours count towards a record's total. Records scoped
     * by query() need no extra filter, so the default lets everything through.
     */
    protected function constrainHours(Builder $query): Builder
    {
        return $query;
    }

    protected function getData(): array
    {
        $labelColumn = $this->labelColumn();
        $hours = fn (Builder $query) => $this->constrainHours($query);

        // Sum the hours in SQL (withSum) instead of the totalLoggedInHours
        // accessor, which lazy-loads the hours relation per record (N+1). One
        // query, fetched once, cached for an hour.
        $rows = $this->remember('data', fn () => $this->query()
            ->whereHas('hours', $hours)
            ->withSum(['hours' => $hours], 'value')
            ->limit(10)
            ->get(['id', $labelColumn])
            ->map(fn ($record) => [
                'label' => $record->{$labelColumn},
                'hours' => (float) ($record->hours_sum_value ?? 0),
            ])
            ->toArray());

        return [
            'datasets' => [
                [
                    'label' => __('Total time logged (hours)'),
                    'data' => array_column($rows, 'hours'),
                    'backgroundColor' => [
                        'rgba(54, 162, 235, .6)',
                    ],
                    'borderColor' => [
                        'rgba(54, 162, 235, .8)',
                    ],
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }
}

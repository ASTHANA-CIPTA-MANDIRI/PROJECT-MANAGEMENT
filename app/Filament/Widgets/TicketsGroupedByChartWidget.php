<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use Filament\Widgets\DoughnutChartWidget;
use Illuminate\Database\Eloquent\Builder;

abstract class TicketsGroupedByChartWidget extends DoughnutChartWidget
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

    /** Model class to group tickets by; must expose a `tickets` relation. */
    abstract protected static function groupingModel(): string;

    protected function getData(): array
    {
        $model = static::groupingModel();

        // Only count tickets the viewer may see, like the dashboard tables do;
        // an unscoped count would expose other tenants' volumes. Cached for one
        // hour: counts change slowly relative to dashboard views.
        $data = $this->remember('counts', fn () => $model::withCount([
            'tickets' => fn (Builder $query) => $query->visibleTo(auth()->user()),
        ])
            ->get(['id', 'name'])
            ->pluck('tickets_count', 'name'));

        return [
            'datasets' => [
                [
                    'label' => $this->getHeading(),
                    'data' => $data->values()->toArray(),
                    'backgroundColor' => [
                        'rgba(255, 99, 132, .6)',
                        'rgba(54, 162, 235, .6)',
                        'rgba(255, 205, 86, .6)',
                    ],
                    'borderColor' => [
                        'rgba(255, 99, 132, .8)',
                        'rgba(54, 162, 235, .8)',
                        'rgba(255, 205, 86, .8)',
                    ],
                    'hoverOffset' => 4,
                ],
            ],
            'labels' => $data->keys()->toArray(),
        ];
    }
}

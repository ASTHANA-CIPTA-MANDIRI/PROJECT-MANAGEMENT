<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use App\Models\TicketType;
use Filament\Widgets\DoughnutChartWidget;

class TicketsByType extends DoughnutChartWidget
{
    use WithCachedData;

    protected static ?int $sort = 2;

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

    protected function getHeading(): string
    {
        return __('Tickets by types');
    }

    protected function getData(): array
    {
        // Cached for one hour: counts change slowly relative to dashboard views.
        $data = $this->remember('counts', fn () => TicketType::withCount('tickets')
            ->get(['id', 'name'])
            ->pluck('tickets_count', 'name'));

        return [
            'datasets' => [
                [
                    'label' => __('Tickets by types'),
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

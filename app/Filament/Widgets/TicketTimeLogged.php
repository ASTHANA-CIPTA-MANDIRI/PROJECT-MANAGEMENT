<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use App\Models\Ticket;
use Filament\Widgets\BarChartWidget;

class TicketTimeLogged extends BarChartWidget
{
    use WithCachedData;

    protected static ?string $heading = 'Chart';

    protected static ?int $sort = 4;

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
        return __('Time logged by tickets');
    }

    protected function getData(): array
    {
        // Sum the hours in SQL (withSum) instead of the totalLoggedInHours
        // accessor, which lazy-loads the hours relation per ticket (N+1). One
        // query, fetched once, cached for an hour.
        $rows = $this->remember('data', fn () => Ticket::query()
            ->has('hours')
            ->withSum('hours', 'value')
            ->limit(10)
            ->get(['id', 'code'])
            ->map(fn (Ticket $ticket) => [
                'code' => $ticket->code,
                'hours' => (float) ($ticket->hours_sum_value ?? 0),
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
            'labels' => array_column($rows, 'code'),
        ];
    }
}

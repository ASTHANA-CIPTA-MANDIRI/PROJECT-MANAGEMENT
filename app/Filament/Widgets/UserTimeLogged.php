<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use App\Models\User;
use Filament\Widgets\BarChartWidget;

class UserTimeLogged extends BarChartWidget
{
    use WithCachedData;

    protected static ?string $heading = 'Chart';

    protected static ?int $sort = 5;

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
        return __('Time logged by users');
    }

    protected function getData(): array
    {
        // Sum hours in SQL (withSum) rather than the totalLoggedInHours
        // accessor, which lazy-loads each user's hours (N+1). One query,
        // fetched once, cached for an hour.
        $rows = $this->remember('data', fn () => User::query()
            ->has('hours')
            ->withSum('hours', 'value')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'name' => $user->name,
                'hours' => (float) ($user->hours_sum_value ?? 0),
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
            'labels' => array_column($rows, 'name'),
        ];
    }
}

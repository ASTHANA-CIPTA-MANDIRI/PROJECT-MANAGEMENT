<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\WithCachedData;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

abstract class TicketsGroupedByChartWidget extends ChartWidget
{
    use WithCachedData;

    protected static ?string $heading = 'Chart';

    protected static ?string $maxHeight = '240px';

    // Visual only (Chart.js options, not data): a bottom legend with
    // dot-style markers instead of the default top legend with square
    // swatches. `layout.padding` leaves room for the selected slice's offset.
    protected static ?array $options = [
        'layout' => ['padding' => 12],
        'plugins' => [
            'legend' => [
                'position' => 'bottom',
                'labels' => [
                    'usePointStyle' => true,
                    'pointStyle' => 'circle',
                    'boxWidth' => 8,
                    'boxHeight' => 8,
                    'padding' => 16,
                ],
            ],
        ],
    ];

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 4,
    ];

    protected function getType(): string
    {
        return 'pie';
    }

    /**
     * Chart options are JSON-encoded, so no callbacks can live here. The click
     * behaviour is a Chart.js plugin in resources/js/filament.js; the texts it
     * needs travel as plugin options so they stay translatable.
     */
    protected function getOptions(): ?array
    {
        return array_replace_recursive(static::$options ?? [], [
            'plugins' => [
                'rencanakanPieDetail' => [
                    'enabled' => true,
                    'hint' => __('Click a slice to see details'),
                    'countLabel' => __('Tickets'),
                    'shareLabel' => __('Share of total'),
                ],
            ],
        ]);
    }

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
        //
        // Fase 3B audit finding: the base $model query itself needs the same
        // visibleTo() scope, not just the nested `tickets` relation count -
        // without it, another Organization's TicketType/TicketPriority NAME
        // still rendered in this chart's labels (with a correctly-zero
        // count, since the ticket count was already scoped), just an
        // existence leak rather than a volume leak.
        $data = $this->remember('counts', fn () => $model::query()
            ->visibleTo(auth()->user())
            ->withCount([
                'tickets' => fn (Builder $query) => $query->visibleTo(auth()->user()),
            ])
            ->get(['id', 'name'])
            ->pluck('tickets_count', 'name'));

        return [
            'datasets' => [
                [
                    'label' => $this->getHeading(),
                    'data' => $data->values()->toArray(),
                    // Blue-accent triad (still just colors, not a palette
                    // the viewer can't tell apart) to match the dashboard's
                    // accent color instead of Chart.js's generic defaults.
                    'backgroundColor' => [
                        'rgba(37, 99, 235, .8)',
                        'rgba(147, 197, 253, .8)',
                        'rgba(30, 41, 59, .8)',
                    ],
                    'borderColor' => [
                        'rgba(37, 99, 235, 1)',
                        'rgba(147, 197, 253, 1)',
                        'rgba(30, 41, 59, 1)',
                    ],
                    'hoverOffset' => 4,
                ],
            ],
            'labels' => $data->keys()->toArray(),
        ];
    }
}

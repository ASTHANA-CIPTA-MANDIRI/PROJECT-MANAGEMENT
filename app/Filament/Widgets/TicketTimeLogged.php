<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class TicketTimeLogged extends TimeLoggedChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 8,
    ];

    protected function getHeading(): string
    {
        return __('Time logged by tickets');
    }

    protected function query(): Builder
    {
        return Ticket::query()->visibleTo(auth()->user());
    }

    protected function labelColumn(): string
    {
        return 'code';
    }
}

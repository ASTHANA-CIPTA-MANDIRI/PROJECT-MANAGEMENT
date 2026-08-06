<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class TicketTimeLogged extends TimeLoggedChartWidget
{
    protected static ?int $sort = 4;

    protected function getHeading(): string
    {
        return __('Time logged by tickets');
    }

    protected function query(): Builder
    {
        return Ticket::query();
    }

    protected function labelColumn(): string
    {
        return 'code';
    }
}

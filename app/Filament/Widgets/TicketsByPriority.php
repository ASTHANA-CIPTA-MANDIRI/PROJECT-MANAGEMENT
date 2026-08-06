<?php

namespace App\Filament\Widgets;

use App\Models\TicketPriority;

class TicketsByPriority extends TicketsGroupedByChartWidget
{
    protected static ?int $sort = 3;

    protected function getHeading(): string
    {
        return __('Tickets by priorities');
    }

    protected static function groupingModel(): string
    {
        return TicketPriority::class;
    }
}

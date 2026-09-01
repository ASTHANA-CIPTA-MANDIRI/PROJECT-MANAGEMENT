<?php

namespace App\Filament\Widgets;

use App\Models\TicketType;

class TicketsByType extends TicketsGroupedByChartWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): string
    {
        return __('Tickets by types');
    }

    protected static function groupingModel(): string
    {
        return TicketType::class;
    }
}

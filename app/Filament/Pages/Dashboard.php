<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Widgets\FavoriteProjects;
use App\Filament\Widgets\LatestActivities;
use App\Filament\Widgets\LatestComments;
use App\Filament\Widgets\LatestProjects;
use App\Filament\Widgets\LatestTickets;
use App\Filament\Widgets\TicketsByPriority;
use App\Filament\Widgets\TicketsByType;
use App\Filament\Widgets\TicketTimeLogged;
use App\Filament\Widgets\UserTimeLogged;
use Filament\Pages\Dashboard as BasePage;

/**
 * No page permission: the panel landing page, open to every user who may access
 * Filament at all. Each widget filters its own records.
 */
class Dashboard extends BasePage
{
    use AuthorizesPageAccess;

    protected static ?string $permission = null;

    protected static bool $shouldRegisterNavigation = false;

    protected function getColumns(): int|array
    {
        return 6;
    }

    protected function getWidgets(): array
    {
        return [
            FavoriteProjects::class,
            LatestActivities::class,
            LatestComments::class,
            LatestProjects::class,
            LatestTickets::class,
            TicketsByPriority::class,
            TicketsByType::class,
            TicketTimeLogged::class,
            UserTimeLogged::class,
        ];
    }
}

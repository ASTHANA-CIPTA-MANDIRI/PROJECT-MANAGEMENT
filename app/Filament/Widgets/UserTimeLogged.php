<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserTimeLogged extends TimeLoggedChartWidget
{
    protected static ?int $sort = 5;

    protected function getHeading(): string
    {
        return __('Time logged by users');
    }

    protected function query(): Builder
    {
        return User::query();
    }

    protected function labelColumn(): string
    {
        return 'name';
    }
}

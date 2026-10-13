<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserTimeLogged extends TimeLoggedChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 4,
    ];

    protected function getHeading(): string
    {
        return __('Time logged by users');
    }

    protected function query(): Builder
    {
        return User::query()->visibleTo(auth()->user());
    }

    /**
     * A colleague's total also has to stay inside the shared projects: only
     * hours logged on tickets the viewer may see are summed.
     */
    protected function constrainHours(Builder $query): Builder
    {
        return $query->whereHas('ticket', fn (Builder $query) => $query->visibleTo(auth()->user()));
    }

    protected function labelColumn(): string
    {
        return 'name';
    }
}

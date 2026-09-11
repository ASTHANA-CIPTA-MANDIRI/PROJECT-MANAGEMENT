<?php

namespace App\Filament\Resources\TicketTypeResource\Pages;

use App\Filament\Resources\TicketTypeResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTicketTypes extends ListRecords
{
    protected static string $resource = TicketTypeResource::class;

    /**
     * Fase 3B — see App\Filament\Resources\ActivityResource\Pages\ListActivities
     * for why the explicit @var annotation is needed (PHPStan cannot resolve
     * a custom scope through parent::getTableQuery()'s generically-typed
     * Builder return otherwise).
     */
    protected function getTableQuery(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\TicketType> $query */
        $query = parent::getTableQuery();

        return $query->visibleTo(auth()->user());
    }

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

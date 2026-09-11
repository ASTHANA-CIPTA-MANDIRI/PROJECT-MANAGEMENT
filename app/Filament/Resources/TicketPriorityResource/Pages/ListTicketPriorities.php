<?php

namespace App\Filament\Resources\TicketPriorityResource\Pages;

use App\Filament\Resources\TicketPriorityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTicketPriorities extends ListRecords
{
    protected static string $resource = TicketPriorityResource::class;

    /**
     * Fase 3B — see App\Filament\Resources\ActivityResource\Pages\ListActivities
     * for why the explicit @var annotation is needed.
     */
    protected function getTableQuery(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\TicketPriority> $query */
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

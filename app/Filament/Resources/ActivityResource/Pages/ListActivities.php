<?php

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Filament\Resources\ActivityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    /**
     * Fase 3B — mirrors ListTicketStatuses::getTableQuery()'s shape: the
     * base ActivityResource::getEloquentQuery() stays unscoped (row/bulk
     * actions like RestoreAction resolve a target record through it
     * directly, not through this filtered listing), so the organization
     * scope belongs here instead.
     */
    protected function getTableQuery(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\Activity> $query */
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

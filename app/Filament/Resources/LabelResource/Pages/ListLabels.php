<?php

namespace App\Filament\Resources\LabelResource\Pages;

use App\Filament\Resources\LabelResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLabels extends ListRecords
{
    protected static string $resource = LabelResource::class;

    /**
     * Fase 3B — see App\Filament\Resources\ActivityResource\Pages\ListActivities
     * for why the explicit @var annotation is needed (PHPStan cannot resolve
     * a custom scope through parent::getTableQuery()'s generically-typed
     * Builder return otherwise).
     */
    protected function getTableQuery(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\Label> $query */
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

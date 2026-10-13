<?php

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPermission extends ViewRecord
{
    protected static string $resource = PermissionResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('Back to permissions'))
                ->icon('heroicon-o-arrow-left')
                ->color('secondary')
                ->extraAttributes(['class' => 'rk-back-action'])
                ->url(PermissionResource::getUrl('index')),

            Actions\EditAction::make(),
        ];
    }
}

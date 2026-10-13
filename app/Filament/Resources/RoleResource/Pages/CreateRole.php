<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('Back to roles'))
                ->icon('heroicon-o-arrow-left')
                ->color('secondary')
                ->extraAttributes(['class' => 'rk-back-action'])
                ->url(RoleResource::getUrl('index')),
        ];
    }
}

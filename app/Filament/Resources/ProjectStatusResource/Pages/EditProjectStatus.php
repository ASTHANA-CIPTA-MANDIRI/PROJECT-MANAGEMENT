<?php

namespace App\Filament\Resources\ProjectStatusResource\Pages;

use App\Filament\Resources\ProjectStatusResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProjectStatus extends EditRecord
{
    protected static string $resource = ProjectStatusResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('Back to project statuses'))
                ->icon('heroicon-o-arrow-left')
                ->color('secondary')
                ->extraAttributes(['class' => 'rk-back-action'])
                ->url(ProjectStatusResource::getUrl('index')),

            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

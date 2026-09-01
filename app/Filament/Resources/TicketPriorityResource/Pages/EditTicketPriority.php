<?php

namespace App\Filament\Resources\TicketPriorityResource\Pages;

use App\Filament\Resources\TicketPriorityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicketPriority extends EditRecord
{
    protected static string $resource = TicketPriorityResource::class;

    protected function getActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

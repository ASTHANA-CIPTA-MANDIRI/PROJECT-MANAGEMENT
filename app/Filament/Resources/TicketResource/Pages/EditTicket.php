<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Forms\TicketForm;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    protected function getActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * TicketPolicy::update() authorizes editing *this* ticket via its own
     * owner_id/responsible_id, which does not require the editor to be a
     * member of the ticket's project at all (e.g. an admin who owns a
     * ticket outside any project they belong to) - so the existing
     * project_id must be left alone here. Only an actual re-parent attempt
     * (project_id changed to something else) is Livewire state the client
     * controls and needs re-checking, the same guard CreateTicket applies
     * unconditionally since every create is "changing" from nothing.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ((int) $data['project_id'] !== (int) $record->getAttribute('project_id')) {
            TicketForm::assertAccessibleProject($data['project_id']);
        }

        return parent::handleRecordUpdate($record, $data);
    }
}

<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Forms\TicketForm;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Create the ticket and its lifecycle writes (code/order generation, epic
     * assignment, status activity) atomically. Queued notifications only fire
     * after the transaction commits ($afterCommit on the notifications).
     *
     * project_id is Livewire state the client controls, and the field's own
     * options list only being scoped to accessible projects stops the
     * rendered dropdown from *offering* a foreign one - it does not stop a
     * crafted request from submitting one directly. Re-resolved here, right
     * before the write, the same way IssueForm::submit() already does for
     * the Road Map's own ticket-creation form.
     */
    protected function handleRecordCreation(array $data): Model
    {
        TicketForm::assertAccessibleProject($data['project_id']);

        return DB::transaction(fn () => parent::handleRecordCreation($data));
    }
}

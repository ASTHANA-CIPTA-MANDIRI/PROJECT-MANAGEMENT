<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
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
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn () => parent::handleRecordCreation($data));
    }
}

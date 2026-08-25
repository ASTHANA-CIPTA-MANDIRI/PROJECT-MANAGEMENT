<?php

declare(strict_types=1);

namespace App\Http\Livewire\Timesheet;

use App\Models\Ticket;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class TimeLogged extends Component implements HasTable
{
    use InteractsWithTable;

    public Ticket $ticket;

    /**
     * Reading logged hours follows TicketPolicy::view, the same rule the
     * parent ticket page already applies - this component must not rely on
     * that alone, since a Livewire component is reachable independently of
     * whichever page happened to render it.
     *
     * The check lives in `booted()`, not `mount()`: mount() only runs on the
     * initial page load, while `booted()` also runs on every subsequent
     * Livewire request. It cannot be `boot()` because that fires before
     * public properties are hydrated, so $this->ticket would not exist yet.
     */
    public function booted(): void
    {
        abort_unless(auth()->user()?->can('view', $this->ticket), 403);
    }

    protected function getFormModel(): Model|string|null
    {
        return $this->ticket;
    }

    protected function getTableQuery(): Builder
    {
        return $this->ticket->hours()->with(['user', 'activity', 'ticket'])->getQuery();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('user.name')
                ->label(__('Owner'))
                ->sortable()
                ->formatStateUsing(fn ($record) => view('components.user-avatar', ['user' => $record->user]))
                ->searchable(),

            Tables\Columns\TextColumn::make('value')
                ->label(__('Hours'))
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('comment')
                ->label(__('Comment'))
                ->limit(50)
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('activity.name')
                ->label(__('Activity'))
                ->sortable(),

            Tables\Columns\TextColumn::make('ticket.name')
                ->label(__('Ticket'))
                ->sortable(),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->sortable()
                ->searchable(),
        ];
    }
}

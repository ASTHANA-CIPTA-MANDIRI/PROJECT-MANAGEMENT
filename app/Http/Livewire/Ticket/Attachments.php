<?php

namespace App\Http\Livewire\Ticket;

use App\Models\Ticket;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Attachments extends Component implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    public Ticket $ticket;

    protected $listeners = [
        'filesUploaded',
    ];

    /**
     * Reading the attachment list follows TicketPolicy::view - the same rule
     * the media.show route already applies to downloading the files
     * themselves, so a read-only project member keeps seeing them here.
     *
     * The check lives in `booted()`, not `mount()`: mount() only runs on the
     * initial page load, while `booted()` also runs on every subsequent
     * Livewire request, before PerformActionCalls reaches any action. It
     * cannot be `boot()` (the hook Concerns\AuthorizesPageAccess uses) because
     * that one fires before public properties are hydrated, so $this->ticket
     * would not exist yet.
     */
    public function booted(): void
    {
        abort_unless(auth()->user()?->can('view', $this->ticket), 403);
    }

    /**
     * Adding or deleting an attachment is a write on the ticket, so it takes
     * TicketPolicy::update - not the view() the parent page checks. Public
     * because the component's own view hides the upload form with it.
     */
    public function canManageAttachments(): bool
    {
        return (bool) auth()->user()?->can('update', $this->ticket);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function render()
    {
        return view('livewire.ticket.attachments');
    }

    protected function getFormModel(): Model|string|null
    {
        return $this->ticket;
    }

    protected function getFormSchema(): array
    {
        if (! $this->canManageAttachments()) {
            return [];
        }

        return [
            SpatieMediaLibraryFileUpload::make('attachments')
                ->label(__('Attachments'))
                ->hint(__('Important: If a file has the same name, it will be replaced'))
                ->helperText(__('Here you can attach all files needed for this ticket'))
                ->multiple()
                ->disablePreview()
                ->acceptedFileTypes(config('system.tickets.attachments.accepted_mime_types'))
                ->maxSize(config('system.max_file_size')),
        ];
    }

    public function perform(): void
    {
        abort_unless($this->canManageAttachments(), 403);

        $this->form->getState();
        $this->form->fill();
        $this->emit('filesUploaded');
        Filament::notify('success', __('Ticket attachments saved'));
    }

    public function filesUploaded(): void
    {
        $this->ticket->refresh();
    }

    protected function getTableQuery(): Builder
    {
        return $this->ticket->media()->getQuery();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label(__('Name'))
                ->sortable()
                ->searchable(),

            TextColumn::make('human_readable_size')
                ->label(__('Size'))
                ->sortable()
                ->searchable(),

            TextColumn::make('mime_type')
                ->label(__('Mime type'))
                ->sortable()
                ->searchable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => $this->canManageAttachments())
                ->action(function ($record) {
                    // visible() above is enforced server-side too - Filament
                    // refuses to mount or call a disabled action, and
                    // Actions\Concerns\CanBeDisabled::isDisabled() counts
                    // hidden as disabled - but that is an indirect guarantee
                    // buried in the framework, so the write states its own
                    // condition rather than inheriting one.
                    abort_unless($this->canManageAttachments(), 403);

                    $record->delete();
                    Filament::notify('success', __('Ticket attachment deleted'));
                }),
        ];
    }
}

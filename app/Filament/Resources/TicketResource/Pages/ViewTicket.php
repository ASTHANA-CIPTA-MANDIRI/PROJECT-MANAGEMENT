<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Exports\TicketHoursExport;
use App\Filament\Resources\TicketResource;
use App\Models\Activity;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\TicketSubscriber;
use App\Support\Mentions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ViewTicket extends ViewRecord implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = TicketResource::class;

    protected static string $view = 'filament.resources.tickets.view';

    public string $tab = 'comments';

    protected $listeners = ['doDeleteComment'];

    public $selectedCommentId;

    public function mount($record): void
    {
        parent::mount($record);
        $this->form->fill();
    }

    protected function getActions(): array
    {
        return [
            Actions\Action::make('toggleSubscribe')
                ->label(
                    fn () => $this->record->subscribers()->where('users.id', auth()->user()->id)->count() ?
                        __('Unsubscribe')
                        : __('Subscribe')
                )
                ->color(
                    fn () => $this->record->subscribers()->where('users.id', auth()->user()->id)->count() ?
                        'danger'
                        : 'success'
                )
                ->icon('heroicon-o-bell')
                ->button()
                ->action(function () {
                    if (
                        $sub = TicketSubscriber::where('user_id', auth()->user()->id)
                            ->where('ticket_id', $this->record->id)
                            ->first()
                    ) {
                        $sub->delete();
                        $this->notify('success', __('You unsubscribed from the ticket'));
                    } else {
                        TicketSubscriber::create([
                            'user_id' => auth()->user()->id,
                            'ticket_id' => $this->record->id,
                        ]);
                        $this->notify('success', __('You subscribed to the ticket'));
                    }
                    $this->record->refresh();
                }),
            Actions\Action::make('share')
                ->label(__('Share'))
                ->color('secondary')
                ->button()
                ->icon('heroicon-o-share')
                ->action(fn () => $this->dispatchBrowserEvent('shareTicket', [
                    'url' => route('filament.resources.tickets.share', $this->record->code),
                ])),
            Actions\EditAction::make(),
            Actions\Action::make('logHours')
                ->label(__('Log time'))
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->modalWidth('sm')
                ->modalHeading(__('Log worked time'))
                ->modalSubheading(__('Use the following form to add your worked time in this ticket.'))
                ->modalButton(__('Log'))
                ->visible(fn () => $this->canLogHours())
                ->form([
                    TextInput::make('time')
                        ->label(__('Time to log'))
                        ->numeric()
                        ->required(),
                    Select::make('activity_id')
                        ->label(__('Activity'))
                        ->searchable()
                        ->reactive()
                        ->options(function ($get, $set) {
                            return Activity::all()->pluck('name', 'id')->toArray();
                        }),
                    Textarea::make('comment')
                        ->label(__('Comment'))
                        ->rows(3),
                ])
                ->action(function (Collection $records, array $data): void {
                    abort_unless($this->canLogHours(), 403);

                    $value = $data['time'];
                    $comment = $data['comment'];
                    TicketHour::create([
                        'ticket_id' => $this->record->id,
                        'activity_id' => $data['activity_id'],
                        'user_id' => auth()->user()->id,
                        'value' => $value,
                        'comment' => $comment,
                    ]);
                    $this->record->refresh();
                    $this->notify('success', __('Time logged into ticket'));
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('exportLogHours')
                    ->label(__('Export time logged'))
                    ->icon('heroicon-o-document-download')
                    ->color('warning')
                    ->visible(
                        fn () => $this->record->watchers->where('id', auth()->user()->id)->count()
                            && $this->record->hours()->count()
                    )
                    ->action(fn () => Excel::download(
                        new TicketHoursExport($this->record),
                        'time_'.str_replace('-', '_', $this->record->code).'.csv',
                        \Maatwebsite\Excel\Excel::CSV,
                        ['Content-Type' => 'text/csv']
                    )),
            ])
                ->visible(fn () => (in_array(
                    auth()->user()->id,
                    [$this->record->owner_id, $this->record->responsible_id]
                )) || (
                    $this->record->watchers->where('id', auth()->user()->id)->count()
                    && $this->record->hours()->count()
                ))
                ->color('secondary'),
        ];
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
    }

    protected function getFormSchema(): array
    {
        return [
            RichEditor::make('comment')
                ->disableLabel()
                ->placeholder(__('Type a new comment'))
                ->helperText(__('Type @ to mention a project member.'))
                ->required(),
        ];
    }

    /**
     * Resolve a comment the current user is really allowed to edit or delete.
     *
     * Both $commentId and $selectedCommentId arrive from the client, so the
     * lookup is scoped to the ticket being viewed and then matched against the
     * same rule the view uses to show the Edit/Delete buttons.
     */
    protected function authorizedComment(?int $commentId): ?TicketComment
    {
        if (! $commentId) {
            return null;
        }

        $comment = $this->record->comments()->whereKey($commentId)->first();

        if (! $comment) {
            return null;
        }

        return $comment->user_id === auth()->user()->id || $this->isAdministrator()
            ? $comment
            : null;
    }

    public function submitComment(): void
    {
        $data = $this->form->getState();
        if ($this->selectedCommentId) {
            if (! $comment = $this->authorizedComment($this->selectedCommentId)) {
                $this->cancelEditComment();
                $this->notify('danger', __('You are not allowed to edit this comment'));

                return;
            }

            // Saved on the model instance (not the query builder) so the
            // content mutator - and with it HtmlSanitizer - still runs.
            $comment->update([
                'content' => $data['comment'],
            ]);
        } else {
            TicketComment::create([
                'user_id' => auth()->user()->id,
                'ticket_id' => $this->record->id,
                'content' => $data['comment'],
            ]);
        }
        $this->record->refresh();
        $this->cancelEditComment();
        $this->notify('success', __('Comment saved'));
    }

    /**
     * Gate for the "Log time" action: authenticated ticket owner/responsible
     * AND allowed by TicketHourPolicy. Filament already refuses to mount or
     * call an action whose visible() is false (Actions\Concerns\CanBeDisabled
     * ::isDisabled() counts hidden as disabled), but that is an indirect
     * guarantee buried in the framework, so action() states its own
     * condition rather than inheriting one - see Attachments::perform() for
     * the same reasoning.
     */
    protected function canLogHours(): bool
    {
        return auth()->user()->can('create', TicketHour::class)
            && in_array(auth()->user()->id, [$this->record->owner_id, $this->record->responsible_id]);
    }

    public function isAdministrator(): bool
    {
        return $this->record->project->isManageableBy(auth()->user());
    }

    public function editComment(int $commentId): void
    {
        if (! $comment = $this->authorizedComment($commentId)) {
            $this->notify('danger', __('You are not allowed to edit this comment'));

            return;
        }

        $content = $comment->content;

        $this->form->fill([
            // Drop the hidden "#id" mention marker so it never shows up as
            // visible, editable text in the comment box.
            'comment' => $content ? Mentions::stripIds($content) : $content,
        ]);
        $this->selectedCommentId = $commentId;
    }

    public function deleteComment(int $commentId): void
    {
        Notification::make()
            ->warning()
            ->title(__('Delete confirmation'))
            ->body(__('Are you sure you want to delete this comment?'))
            ->actions([
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->color('danger')
                    ->button()
                    ->close()
                    ->emit('doDeleteComment', compact('commentId')),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->close(),
            ])
            ->persistent()
            ->send();
    }

    public function doDeleteComment(int $commentId): void
    {
        if (! $comment = $this->authorizedComment($commentId)) {
            $this->notify('danger', __('You are not allowed to delete this comment'));

            return;
        }

        $comment->delete();
        $this->record->refresh();
        $this->notify('success', __('Comment deleted'));
    }

    public function cancelEditComment(): void
    {
        $this->form->fill();
        $this->selectedCommentId = null;
    }
}

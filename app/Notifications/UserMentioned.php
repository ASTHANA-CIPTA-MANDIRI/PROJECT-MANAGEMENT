<?php

namespace App\Notifications;

use App\Models\TicketComment;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a project member who was @mentioned in a ticket comment. More
 * specific than TicketCommented — a mentioned user receives this instead of
 * the generic "new comment" notice.
 */
class UserMentioned extends Notification implements ShouldQueue
{
    use Queueable;

    private TicketComment $ticketComment;

    public function __construct(TicketComment $ticketComment)
    {
        // Defer queued dispatch until the surrounding DB transaction commits.
        $this->afterCommit = true;
        $this->ticketComment = $ticketComment;
    }

    /**
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->line(
                __(
                    ':name mentioned you in a comment on the ticket :ticket.',
                    [
                        'name' => $this->ticketComment->user->name,
                        'ticket' => $this->ticketComment->ticket->name,
                    ]
                )
            )
            ->line(__('See more details of this ticket by clicking on the button below:'))
            ->action(
                __('View details'),
                route('filament.resources.tickets.share', $this->ticketComment->ticket->code)
            );
    }

    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(
                __(
                    'You were mentioned on :ticket',
                    [
                        'ticket' => $this->ticketComment->ticket->name,
                    ]
                )
            )
            ->icon('heroicon-o-at-symbol')
            ->body(fn () => __('by :name', ['name' => $this->ticketComment->user->name]))
            ->actions([
                Action::make('view')
                    ->link()
                    ->icon('heroicon-s-eye')
                    ->url(fn () => route('filament.resources.tickets.share', $this->ticketComment->ticket->code)),
            ])
            ->getDatabaseMessage();
    }
}

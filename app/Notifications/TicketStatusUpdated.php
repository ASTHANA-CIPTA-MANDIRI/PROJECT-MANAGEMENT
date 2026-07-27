<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    private Ticket $ticket;

    private ?TicketActivity $activity;

    /**
     * @param  TicketActivity|null  $activity  The status change that triggered
     *                                         this notification. Callers should pass it explicitly; when omitted we
     *                                         fall back to the freshest recorded activity via a query (never the
     *                                         possibly-stale loaded relation) so this can't crash.
     */
    public function __construct(Ticket $ticket, ?TicketActivity $activity = null)
    {
        // Defer queued dispatch until the surrounding DB transaction commits.
        $this->afterCommit = true;
        $this->ticket = $ticket;
        $this->activity = $activity ?? $ticket->activities()->latest('id')->first();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $mail = (new MailMessage)
            ->line(__('The status of ticket :ticket has been updated.', ['ticket' => $this->ticket->name]));

        if ($this->activity) {
            $mail->line('- '.__('Old status:').' '.$this->activity->oldStatus->name)
                ->line('- '.__('New status:').' '.$this->activity->newStatus->name);
        }

        return $mail
            ->line(__('See more details of this ticket by clicking on the button below:'))
            ->action(__('View details'), route('filament.resources.tickets.share', $this->ticket->code));
    }

    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Ticket status updated'))
            ->icon('heroicon-o-ticket')
            ->body(
                fn () => $this->activity
                    ? __('Old status: :oldStatus - New status: :newStatus', [
                        'oldStatus' => $this->activity->oldStatus->name,
                        'newStatus' => $this->activity->newStatus->name,
                    ])
                    : __('The status of ticket :ticket has been updated.', ['ticket' => $this->ticket->name])
            )
            ->actions([
                Action::make('view')
                    ->link()
                    ->icon('heroicon-s-eye')
                    ->url(fn () => route('filament.resources.tickets.share', $this->ticket->code)),
            ])
            ->getDatabaseMessage();
    }
}

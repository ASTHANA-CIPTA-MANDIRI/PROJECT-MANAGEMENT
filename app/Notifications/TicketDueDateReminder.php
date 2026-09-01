<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketDueDateReminder extends Notification implements ShouldQueue
{
    use Queueable;

    private Ticket $ticket;

    private bool $isDueToday;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Ticket $ticket, bool $isDueToday)
    {
        // Defer queued dispatch until the surrounding DB transaction commits.
        $this->afterCommit = true;
        $this->ticket = $ticket;
        $this->isDueToday = $isDueToday;
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
        return (new MailMessage)
            ->line($this->isDueToday
                ? __('A ticket you are involved with is due today.')
                : __('A ticket you are involved with is due tomorrow.'))
            ->line('- '.__('Ticket name:').' '.$this->ticket->name)
            ->line('- '.__('Project:').' '.$this->ticket->project->name)
            ->line('- '.__('Due date:').' '.$this->ticket->due_date->toFormattedDateString())
            ->line(__('See more details of this ticket by clicking on the button below:'))
            ->action(__('View details'), route('filament.resources.tickets.share', $this->ticket->code));
    }

    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->isDueToday ? __('Ticket due today') : __('Ticket due tomorrow'))
            ->icon('heroicon-o-clock')
            ->body(fn () => $this->ticket->name)
            ->actions([
                Action::make('view')
                    ->link()
                    ->icon('heroicon-s-eye')
                    ->url(fn () => route('filament.resources.tickets.share', $this->ticket->code)),
            ])
            ->getDatabaseMessage();
    }
}

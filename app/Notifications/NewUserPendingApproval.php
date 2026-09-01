<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells administrators that someone registered and is waiting to be granted a
 * role/package, so the pending user isn't forgotten.
 */
class NewUserPendingApproval extends Notification implements ShouldQueue
{
    use Queueable;

    private User $registrant;

    public function __construct(User $registrant)
    {
        $this->afterCommit = true;
        $this->registrant = $registrant;
    }

    /**
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(__('A new user is awaiting approval'))
            ->line(__('A new user has registered and is awaiting approval.'))
            ->line('- '.__('Name:').' '.$this->registrant->name)
            ->line('- '.__('Email:').' '.$this->registrant->email)
            ->line(__('Assign them a role to grant access.'))
            ->action(__('Review users'), route('filament.resources.users.index'));
    }

    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('New user awaiting approval'))
            ->icon('heroicon-o-user-add')
            ->body(fn () => $this->registrant->name.' ('.$this->registrant->email.')')
            ->actions([
                Action::make('review')
                    ->link()
                    ->icon('heroicon-s-eye')
                    ->url(fn () => route('filament.resources.users.index')),
            ])
            ->getDatabaseMessage();
    }
}

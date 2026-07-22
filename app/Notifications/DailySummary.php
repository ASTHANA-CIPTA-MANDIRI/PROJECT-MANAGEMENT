<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A per-user digest of the previous day's activity across their projects.
 */
class DailySummary extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{new_tickets:int, status_changes:int, comments:int, hours_logged:float} $summary
     */
    public function __construct(
        public CarbonInterface $date,
        public array $summary,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $day = $this->date->isoFormat('dddd, D MMMM Y');

        return (new MailMessage)
            ->subject(__('Daily summary for :date', ['date' => $this->date->toDateString()]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Here is what happened across your projects on :day.', ['day' => $day]))
            ->line('- ' . __(':count new ticket(s)', ['count' => $this->summary['new_tickets']]))
            ->line('- ' . __(':count status change(s)', ['count' => $this->summary['status_changes']]))
            ->line('- ' . __(':count new comment(s)', ['count' => $this->summary['comments']]))
            ->line('- ' . __(':hours hour(s) logged', ['hours' => round($this->summary['hours_logged'], 2)]))
            ->action(__('Open dashboard'), url('/'))
            ->line(__('Have a great day!'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return $this->summary + ['date' => $this->date->toDateString()];
    }
}

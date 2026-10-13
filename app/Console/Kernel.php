<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Email each user yesterday's activity digest, every morning.
        $schedule->command('reports:daily')
            ->dailyAt('07:00')
            ->withoutOverlapping();

        // Archive activities older than 90 days, weekly.
        $schedule->command('cleanup:old-activities --days=90')
            ->weeklyOn(1, '02:00')
            ->withoutOverlapping();

        // Remind ticket owners & responsibles of tickets due tomorrow or today.
        $schedule->command('tickets:due-date-reminders')
            ->dailyAt('08:00')
            ->withoutOverlapping();

        // Permanently remove soft-deleted records past their retention period
        // (currently just TicketComment::prunable(), 90 days - see that model).
        $schedule->command('model:prune')
            ->dailyAt('03:00')
            ->withoutOverlapping();

        // The inbox keeps notifications the user has already read for 90
        // days; unread ones are never touched.
        $schedule->call(fn () => \Illuminate\Notifications\DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(90))
            ->delete())
            ->name('notifications:prune-read')
            ->dailyAt('03:30');

        // Chat files/images are removed after system.chat.attachment_days
        // (CHAT_ATTACHMENT_DAYS, default 30); the message keeps an "expired" note.
        $schedule->call(fn () => \App\Models\MessageAttachment::purgeExpired())
            ->name('chat:purge-attachments')
            ->dailyAt('03:45');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

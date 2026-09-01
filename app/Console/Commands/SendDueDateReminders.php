<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Notifications\TicketDueDateReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Notifies each ticket's owner and responsible user when the ticket is due
 * tomorrow (H-1) or due today, so deadlines aren't missed silently.
 */
class SendDueDateReminders extends Command
{
    protected $signature = 'tickets:due-date-reminders
        {--date= : The day to treat as "today" (YYYY-MM-DD); defaults to today}';

    protected $description = 'Send due-date reminder notifications (H-1 and due day) to ticket owners and responsible users';

    public function handle(): int
    {
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $tomorrow = $today->copy()->addDay();

        $sent = 0;
        $sent += $this->remind($today, isDueToday: true);
        $sent += $this->remind($tomorrow, isDueToday: false);

        $this->info("Due-date reminders sent to {$sent} recipient(s) for {$today->toDateString()}.");

        return self::SUCCESS;
    }

    private function remind(Carbon $date, bool $isDueToday): int
    {
        $sent = 0;

        Ticket::query()
            // A half-open range on the raw column is sargable on every driver;
            // whereDate() wraps the column in DATE(...), which defeats a
            // B-tree index on MySQL. (Eloquent's date cast stores full
            // datetime strings on SQLite, so a plain equality check here
            // isn't portable — the range still works regardless.)
            ->where('due_date', '>=', $date)
            ->where('due_date', '<', $date->copy()->addDay())
            ->whereHas('status', fn ($query) => $query->where('is_final', false))
            ->with(['owner', 'responsible'])
            ->chunkById(200, function ($tickets) use ($isDueToday, &$sent) {
                foreach ($tickets as $ticket) {
                    $recipients = collect([$ticket->owner, $ticket->responsible])
                        ->filter()
                        ->unique('id');

                    foreach ($recipients as $recipient) {
                        $recipient->notify(new TicketDueDateReminder($ticket, $isDueToday));
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}

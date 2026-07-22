<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\User;
use App\Notifications\DailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Emails each user a summary of the previous day's activity across the projects
 * they own or belong to. Users with no activity that day are skipped.
 */
class GenerateDailyReports extends Command
{
    protected $signature = 'reports:daily
        {--date= : The day to report on (YYYY-MM-DD); defaults to yesterday}';

    protected $description = 'Send each user a daily summary email of their projects\' activity';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $sent = 0;

        User::query()
            ->with(['projectsOwning', 'projectsAffected'])
            ->chunkById(200, function ($users) use ($start, $end, $date, &$sent) {
                foreach ($users as $user) {
                    $projectIds = $user->projectsOwning->pluck('id')
                        ->merge($user->projectsAffected->pluck('id'))
                        ->unique()
                        ->values();

                    if ($projectIds->isEmpty()) {
                        continue;
                    }

                    $summary = $this->summaryFor($projectIds->all(), $start, $end);

                    // Skip users with nothing to report - no empty emails.
                    if ($this->isEmpty($summary)) {
                        continue;
                    }

                    $user->notify(new DailySummary($date, $summary));
                    $sent++;
                }
            });

        $this->info("Daily summary sent to {$sent} user(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array{new_tickets:int, status_changes:int, comments:int, hours_logged:float}
     */
    private function summaryFor(array $projectIds, Carbon $start, Carbon $end): array
    {
        $inProjects = fn ($query) => $query->whereIn('project_id', $projectIds);

        return [
            'new_tickets' => Ticket::whereIn('project_id', $projectIds)
                ->whereBetween('created_at', [$start, $end])->count(),
            'status_changes' => TicketActivity::whereBetween('created_at', [$start, $end])
                ->whereHas('ticket', $inProjects)->count(),
            'comments' => TicketComment::whereBetween('created_at', [$start, $end])
                ->whereHas('ticket', $inProjects)->count(),
            'hours_logged' => (float) TicketHour::whereBetween('created_at', [$start, $end])
                ->whereHas('ticket', $inProjects)->sum('value'),
        ];
    }

    private function isEmpty(array $summary): bool
    {
        return $summary['new_tickets'] === 0
            && $summary['status_changes'] === 0
            && $summary['comments'] === 0
            && (float) $summary['hours_logged'] === 0.0;
    }
}

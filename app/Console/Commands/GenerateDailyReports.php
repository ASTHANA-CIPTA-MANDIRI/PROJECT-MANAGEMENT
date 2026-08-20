<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\User;
use App\Notifications\DailySummary;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emails each user a summary of the previous day's activity across the projects
 * they own or belong to. Users with no activity that day are skipped.
 *
 * The aggregates run per project, not per user: four GROUP BY queries cover the
 * whole instance, and the results are then fanned out to owners and members.
 * Counting per user instead would cost four queries each, so a 10k-user
 * instance would spend 40k queries every morning.
 *
 * Recipients are then streamed in chunks. Each user's totals are summed from
 * the per-project maps during their own chunk, so nothing is accumulated across
 * iterations and peak memory stays flat no matter how many people are mailed.
 */
class GenerateDailyReports extends Command
{
    /** Recipients held in memory at once. */
    private const CHUNK_SIZE = 200;

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

        $metrics = $this->metricsByProject($start, $end);

        // Only projects that saw something that day can produce a summary.
        $activeProjectIds = collect($metrics)
            ->flatMap(fn (array $byProject) => array_keys($byProject))
            ->unique()
            ->values()
            ->all();

        $sent = $activeProjectIds === []
            ? 0
            : $this->notifyRecipients($activeProjectIds, $metrics, $date);

        $this->info("Daily summary sent to {$sent} user(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }

    /**
     * Each metric collapsed into one GROUP BY project_id query.
     *
     * @return array<string, array<int, float>> metric name => [project id => value]
     */
    private function metricsByProject(Carbon $start, Carbon $end): array
    {
        return [
            'new_tickets' => $this->perProject(
                Ticket::query()->whereBetween('tickets.created_at', [$start, $end]),
                'count(*)',
            ),
            // TicketActivity::ticket() is withTrashed(), so activity on a
            // deleted ticket still counts - as it did per user before.
            'status_changes' => $this->perProject(
                TicketActivity::query()
                    ->join('tickets', 'tickets.id', '=', 'ticket_activities.ticket_id')
                    ->whereBetween('ticket_activities.created_at', [$start, $end]),
                'count(*)',
            ),
            'comments' => $this->perProject(
                TicketComment::query()
                    ->join('tickets', 'tickets.id', '=', 'ticket_comments.ticket_id')
                    ->whereNull('tickets.deleted_at')
                    ->whereBetween('ticket_comments.created_at', [$start, $end]),
                'count(*)',
            ),
            'hours_logged' => $this->perProject(
                TicketHour::query()
                    ->join('tickets', 'tickets.id', '=', 'ticket_hours.ticket_id')
                    ->whereNull('tickets.deleted_at')
                    ->whereBetween('ticket_hours.created_at', [$start, $end]),
                'sum(ticket_hours.value)',
            ),
        ];
    }

    /**
     * @param  string  $aggregate  A code-controlled SQL aggregate expression.
     * @return array<int, float> project id => aggregate value
     */
    private function perProject(Builder $query, string $aggregate): array
    {
        return $query
            ->groupBy('tickets.project_id')
            ->select([
                DB::raw('tickets.project_id as project_id'),
                DB::raw($aggregate.' as aggregate'),
            ])
            ->pluck('aggregate', 'project_id')
            ->all();
    }

    /**
     * Walk the users attached to an active project, chunk by chunk, and mail
     * each one their summary. Only the projects that actually saw activity are
     * loaded per user, and trashed projects drop out on their own because both
     * relations go through the Project model's soft-delete scope.
     *
     * @param  array<int, int>  $activeProjectIds
     * @param  array<string, array<int, float>>  $metrics
     */
    private function notifyRecipients(array $activeProjectIds, array $metrics, Carbon $date): int
    {
        $onlyActive = fn ($query) => $query->whereIn('projects.id', $activeProjectIds);

        $sent = 0;

        User::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('projectsOwning', $onlyActive)
                ->orWhereHas('projectsAffected', $onlyActive))
            ->with([
                // owner_id is what hasMany matches the eager load on.
                'projectsOwning' => fn ($query) => $onlyActive($query)->select(['projects.id', 'projects.owner_id']),
                'projectsAffected' => fn ($query) => $onlyActive($query)->select('projects.id'),
            ])
            ->chunkById(self::CHUNK_SIZE, function ($users) use ($metrics, $date, &$sent) {
                foreach ($users as $user) {
                    $projectIds = $user->projectsOwning->pluck('id')
                        ->merge($user->projectsAffected->pluck('id'))
                        ->unique();

                    $summary = $this->summaryFor($projectIds, $metrics);

                    // Skip users with nothing to report - no empty emails.
                    if ($this->isEmpty($summary)) {
                        continue;
                    }

                    $user->notify(new DailySummary($date, $summary));
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $projectIds
     * @param  array<string, array<int, float>>  $metrics
     * @return array{new_tickets:int, status_changes:int, comments:int, hours_logged:float}
     */
    private function summaryFor(Collection $projectIds, array $metrics): array
    {
        $summary = [
            'new_tickets' => 0,
            'status_changes' => 0,
            'comments' => 0,
            'hours_logged' => 0.0,
        ];

        foreach ($projectIds as $projectId) {
            $summary['new_tickets'] += (int) ($metrics['new_tickets'][$projectId] ?? 0);
            $summary['status_changes'] += (int) ($metrics['status_changes'][$projectId] ?? 0);
            $summary['comments'] += (int) ($metrics['comments'][$projectId] ?? 0);
            $summary['hours_logged'] += (float) ($metrics['hours_logged'][$projectId] ?? 0);
        }

        return $summary;
    }

    private function isEmpty(array $summary): bool
    {
        return $summary['new_tickets'] === 0
            && $summary['status_changes'] === 0
            && $summary['comments'] === 0
            && (float) $summary['hours_logged'] === 0.0;
    }
}

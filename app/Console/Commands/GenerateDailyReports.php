<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\User;
use App\Notifications\DailySummary;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emails each user a summary of the previous day's activity across the projects
 * they own or belong to. Users with no activity that day are skipped.
 *
 * The aggregates run per project, not per user: four GROUP BY queries cover the
 * whole instance, and the results are then fanned out to owners and members.
 * Counting per user instead would cost four queries each, so a 10k-user
 * instance would spend 40k queries every morning.
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

        $metrics = $this->metricsByProject($start, $end);

        // Only projects that saw something that day can produce a summary.
        $activeProjectIds = collect($metrics)
            ->flatMap(fn (array $byProject) => array_keys($byProject))
            ->unique()
            ->values()
            ->all();

        $totals = $this->summariesByUser($activeProjectIds, $metrics);

        $sent = 0;

        User::query()
            ->whereIn('id', array_keys($totals))
            ->chunkById(200, function ($users) use ($totals, $date, &$sent) {
                foreach ($users as $user) {
                    $summary = $totals[$user->id];

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
     * Fan the per-project totals out to every owner and member, summing the
     * projects each user can see. Trashed projects drop out here, because the
     * lookup goes through the Project model's soft-delete scope.
     *
     * @param  array<int, int>  $activeProjectIds
     * @param  array<string, array<int, float>>  $metrics
     * @return array<int, array{new_tickets:int, status_changes:int, comments:int, hours_logged:float}>
     */
    private function summariesByUser(array $activeProjectIds, array $metrics): array
    {
        if ($activeProjectIds === []) {
            return [];
        }

        $totals = [];

        Project::query()
            ->whereIn('id', $activeProjectIds)
            ->with('users:id')
            ->select(['id', 'owner_id'])
            ->chunkById(200, function ($projects) use (&$totals, $metrics) {
                foreach ($projects as $project) {
                    $recipients = $project->users->pluck('id')->all();

                    if ($project->owner_id) {
                        $recipients[] = $project->owner_id;
                    }

                    foreach (array_unique($recipients) as $userId) {
                        $totals[$userId] ??= [
                            'new_tickets' => 0,
                            'status_changes' => 0,
                            'comments' => 0,
                            'hours_logged' => 0.0,
                        ];

                        $totals[$userId]['new_tickets'] += (int) ($metrics['new_tickets'][$project->id] ?? 0);
                        $totals[$userId]['status_changes'] += (int) ($metrics['status_changes'][$project->id] ?? 0);
                        $totals[$userId]['comments'] += (int) ($metrics['comments'][$project->id] ?? 0);
                        $totals[$userId]['hours_logged'] += (float) ($metrics['hours_logged'][$project->id] ?? 0);
                    }
                }
            });

        return $totals;
    }

    private function isEmpty(array $summary): bool
    {
        return $summary['new_tickets'] === 0
            && $summary['status_changes'] === 0
            && $summary['comments'] === 0
            && (float) $summary['hours_logged'] === 0.0;
    }
}

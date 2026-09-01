<?php

namespace App\Console\Commands;

use App\Models\TicketActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Archives ticket status-change activities older than a threshold: each old
 * record is written to a JSON Lines file under storage/app/archives, then
 * removed from the database so the table stays lean while history is kept.
 */
class CleanupOldActivities extends Command
{
    protected $signature = 'cleanup:old-activities
        {--days=90 : Archive activities older than this many days}
        {--dry-run : Report what would be archived without changing anything}';

    protected $description = 'Archive old ticket activities to a file and remove them from the database';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = TicketActivity::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No activities older than {$days} days to archive.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$count} activities older than {$days} days would be archived.");

            return self::SUCCESS;
        }

        $path = 'archives/ticket-activities-'.now()->format('Y-m-d-His').'.jsonl';
        Storage::disk('local')->put($path, '');

        $archived = 0;
        // chunkById + delete is safe: pagination always moves forward by id.
        $query->orderBy('id')->chunkById(500, function ($rows) use ($path, &$archived) {
            $lines = $rows->map(fn (TicketActivity $a) => json_encode($a->toArray()))->implode(PHP_EOL);
            Storage::disk('local')->append($path, $lines);

            TicketActivity::whereIn('id', $rows->pluck('id'))->delete();
            $archived += $rows->count();
        });

        $this->info("Archived {$archived} activities to storage/app/{$path} and removed them from the database.");

        return self::SUCCESS;
    }
}

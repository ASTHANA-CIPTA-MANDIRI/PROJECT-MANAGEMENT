<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\ProjectStatus;
use App\Models\TicketPriority;
use App\Models\TicketType;
use Illuminate\Console\Command;

/**
 * Fase 3B (Tenant Isolation) backfill — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * Every reference-data row seeded before this phase (organization_id was
 * added nullable, see 2026_09_11_000000_add_organization_id_to_activities_table.php)
 * has no Organization. Mirrors App\Console\Commands\BackfillOrganizations
 * exactly: assign them all to the "Default Organization" so existing
 * installations keep working unchanged — every user already connected to
 * that Organization (via BackfillOrganizations) keeps seeing exactly the
 * reference data they see today, just now attributed to their Organization
 * instead of floating free.
 *
 * Requires organizations:backfill to have already run (Default Organization
 * must exist) — this command does not create it itself, to avoid
 * duplicating that command's own idempotent-creation logic.
 *
 * MODELS is deliberately a list, not a single Activity::class special case:
 * TicketType/TicketPriority/Label/ProjectStatus join it as Fase 3B extends
 * organization_id to them, at which point this command needs no further
 * changes beyond adding their class names here.
 */
class BackfillLookupData extends Command
{
    protected $signature = 'lookup-data:backfill
        {--dry-run : Report what would change without changing anything}';

    protected $description = 'Assign existing reference data (Activity, and more as Fase 3B extends) with no Organization to the Default Organization';

    private const DEFAULT_ORGANIZATION_NAME = 'Default Organization';

    /** @var array<int, class-string> */
    private const MODELS = [
        Activity::class,
        TicketType::class,
        TicketPriority::class,
        ProjectStatus::class,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = [];
        foreach (self::MODELS as $modelClass) {
            $counts[$modelClass] = $modelClass::withTrashed()->whereNull('organization_id')->count();
        }

        $this->printReport($counts);

        if (array_sum($counts) === 0) {
            $this->line('');
            $this->info('Tidak ada yang perlu di-backfill.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('');
            $this->info('[dry-run] Tidak ada perubahan yang disimpan.');

            return self::SUCCESS;
        }

        $organization = Organization::where('name', self::DEFAULT_ORGANIZATION_NAME)->first();

        if ($organization === null) {
            $this->error('"Default Organization" tidak ditemukan. Jalankan `organizations:backfill` dulu sebelum command ini.');

            return self::FAILURE;
        }

        foreach (self::MODELS as $modelClass) {
            $updated = $modelClass::withTrashed()
                ->whereNull('organization_id')
                ->update(['organization_id' => $organization->id]);

            $this->info("{$modelClass}: {$updated} baris di-assign ke \"{$organization->name}\".");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<class-string, int>  $counts
     */
    private function printReport(array $counts): void
    {
        $this->line('Reference data requiring organization_id:');
        foreach ($counts as $modelClass => $count) {
            $this->line("  {$modelClass}: {$count}");
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket codes were derived from a live count of the project's tickets, so a
 * soft-deleted ticket freed its number and the next ticket reused it (and two
 * concurrent creations could pick the same number). Duplicate codes make the
 * public share route (/tickets/share/{ticket:code}) resolve to an arbitrary
 * ticket. Move the numbering to a per-project counter, renumber the duplicates
 * that already exist, and let the database enforce uniqueness from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The column may already exist from a partially applied run (MySQL
        // auto-commits DDL, so a failure during the backfill leaves it behind).
        if (! Schema::hasColumn('projects', 'last_ticket_number')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->unsignedInteger('last_ticket_number')->default(0)->after('ticket_prefix');
            });
        }

        $this->backfillCountersAndRenumberDuplicates();

        // `code` comes first so the same index also serves the share route's
        // lookup by code, which until now was a full table scan.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unique(['code', 'project_id']);
        });
    }

    /**
     * Set each project's counter to the highest ticket number it ever handed
     * out — soft-deleted tickets included, since they keep their code — and
     * give any duplicate code a fresh number above that high-water mark.
     * Renumbering (rather than deleting) keeps every ticket intact; each change
     * is logged so an outdated bookmark can still be traced back.
     */
    private function backfillCountersAndRenumberDuplicates(): void
    {
        DB::table('projects')->orderBy('id')->get(['id', 'ticket_prefix'])
            ->each(function ($project) {
                $tickets = DB::table('tickets')
                    ->where('project_id', $project->id)
                    ->orderBy('id')
                    ->get(['id', 'code']);

                $highest = $tickets
                    ->map(fn ($ticket) => $this->numberFromCode($ticket->code, $project->ticket_prefix))
                    ->filter()
                    ->max() ?? 0;

                $seen = [];
                foreach ($tickets as $ticket) {
                    if (! isset($seen[$ticket->code])) {
                        $seen[$ticket->code] = $ticket->id;

                        continue;
                    }

                    $newCode = $project->ticket_prefix.'-'.(++$highest);
                    Log::warning('Migrasi kode tiket: menomori ulang kode yang duplikat', [
                        'ticket_id' => $ticket->id,
                        'kept_ticket_id' => $seen[$ticket->code],
                        'old_code' => $ticket->code,
                        'new_code' => $newCode,
                    ]);
                    DB::table('tickets')->where('id', $ticket->id)->update(['code' => $newCode]);
                    $seen[$newCode] = $ticket->id;
                }

                DB::table('projects')->where('id', $project->id)
                    ->update(['last_ticket_number' => $highest]);
            });
    }

    /**
     * The numeric part of a "PREFIX-12" code, or null when the code does not
     * follow the project's current prefix (e.g. the prefix was renamed later).
     */
    private function numberFromCode(?string $code, string $prefix): ?int
    {
        if ($code === null || ! preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $code, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['code', 'project_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('last_ticket_number');
        });
    }
};

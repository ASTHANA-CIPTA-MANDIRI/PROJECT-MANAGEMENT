<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SearchService scopes comment search to the projects a user can access.
 * Doing that used to require pulling every accessible project's ticket ids
 * into memory first (Ticket::whereIn('project_id', ...)->pluck('id')) so it
 * could filter comments by ticket_id — unbounded for users with access to a
 * lot of tickets. Denormalizing project_id straight onto ticket_comments lets
 * SearchService filter directly, the same way Ticket::project_id already
 * does for ticket search, and (per Laravel Scout's collection engine) the
 * filter key must be a real column for the default/testing driver to work
 * the same way it does against Meilisearch/Algolia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('ticket_id')->constrained('projects');
        });

        $this->backfillProjectId();

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }

    /**
     * One update per ticket (not per comment) so this stays cheap even on a
     * large ticket_comments table. Portable across MySQL/SQLite.
     */
    private function backfillProjectId(): void
    {
        DB::table('tickets')->select('id', 'project_id')->orderBy('id')->chunk(500, function ($tickets) {
            foreach ($tickets as $ticket) {
                DB::table('ticket_comments')
                    ->where('ticket_id', $ticket->id)
                    ->update(['project_id' => $ticket->project_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};

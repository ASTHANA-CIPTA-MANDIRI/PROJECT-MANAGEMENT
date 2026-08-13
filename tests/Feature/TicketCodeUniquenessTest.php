<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers 2026_08_13_000000_add_ticket_number_counter_and_unique_ticket_code:
 * the per-project counter, the (code, project_id) unique index that also
 * indexes the public share route's lookup, and the renumbering of codes that
 * were already duplicated before the migration ran.
 */
class TicketCodeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<int, string>>
     */
    private function indexedColumnSets(string $table): array
    {
        return collect(DB::select("PRAGMA index_list($table)"))->map(function ($index) {
            return collect(DB::select("PRAGMA index_info({$index->name})"))
                ->sortBy('seqno')->pluck('name')->all();
        })->all();
    }

    public function test_tickets_code_is_indexed_first_so_share_links_do_not_scan_the_table(): void
    {
        $this->assertContains(['code', 'project_id'], $this->indexedColumnSets('tickets'));
    }

    public function test_the_database_rejects_a_duplicate_code_inside_a_project(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'UNQ']);
        $first = Ticket::factory()->create(['project_id' => $project->id]);
        $second = Ticket::factory()->create(['project_id' => $project->id]);

        $this->expectException(QueryException::class);

        DB::table('tickets')->where('id', $second->id)->update(['code' => $first->code]);
    }

    public function test_the_project_counter_tracks_the_last_number_handed_out(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'CNT']);

        Ticket::factory()->count(3)->create(['project_id' => $project->id]);

        $this->assertSame(3, (int) DB::table('projects')->where('id', $project->id)->value('last_ticket_number'));
    }

    public function test_the_migration_renumbers_codes_that_were_already_duplicated(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'DUP']);
        $first = Ticket::factory()->create(['project_id' => $project->id]);
        $second = Ticket::factory()->create(['project_id' => $project->id]);
        $third = Ticket::factory()->create(['project_id' => $project->id]);

        // Recreate the pre-migration state: counter never used, no unique
        // index, and a code reused by a later ticket (what the old count()+1
        // produced after a soft delete).
        Schema::table('tickets', fn (Blueprint $table) => $table->dropUnique(['code', 'project_id']));
        DB::table('projects')->where('id', $project->id)->update(['last_ticket_number' => 0]);
        DB::table('tickets')->where('id', $third->id)->update(['code' => 'DUP-1']);

        Log::spy();

        $migration = require database_path('migrations/2026_08_13_000000_add_ticket_number_counter_and_unique_ticket_code.php');
        $migration->up();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($third, $first) {
                return str_contains($message, 'menomori ulang kode yang duplikat')
                    && $context['ticket_id'] === $third->id
                    && $context['kept_ticket_id'] === $first->id
                    && $context['old_code'] === 'DUP-1'
                    && $context['new_code'] === 'DUP-3';
            });

        $this->assertSame('DUP-1', $first->fresh()->code);
        $this->assertSame('DUP-2', $second->fresh()->code);
        $this->assertSame('DUP-3', $third->fresh()->code);

        // The counter picks up above the highest number ever used, so the next
        // ticket cannot land on an existing code.
        $fourth = Ticket::factory()->create(['project_id' => $project->id]);
        $this->assertSame('DUP-4', $fourth->code);
    }
}

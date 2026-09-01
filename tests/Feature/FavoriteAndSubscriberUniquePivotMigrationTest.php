<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FavoriteAndSubscriberUniquePivotMigrationTest extends TestCase
{
    use RefreshDatabase;

    // ----------------------------------------------------- project_favorites

    public function test_a_project_cannot_be_favorited_twice_by_the_same_user(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $this->insertFavorite($user->id, $project->id);

        $this->expectException(QueryException::class);

        $this->insertFavorite($user->id, $project->id);
    }

    public function test_the_same_user_can_still_favorite_several_projects(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->create();
        $second = Project::factory()->create();

        $this->insertFavorite($user->id, $first->id);
        $this->insertFavorite($user->id, $second->id);

        $this->assertSame(2, DB::table('project_favorites')->where('user_id', $user->id)->count());
    }

    public function test_pre_existing_favorite_duplicates_are_collapsed_and_logged(): void
    {
        $this->dropUniqueIndex('project_favorites', ['user_id', 'project_id']);
        $this->dropUniqueIndex('ticket_subscribers', ['user_id', 'ticket_id']);

        $user = User::factory()->create();
        $project = Project::factory()->create();

        $keptId = $this->insertFavorite($user->id, $project->id);
        $duplicateId = $this->insertFavorite($user->id, $project->id);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($duplicateId) {
                return str_contains($message, 'project_favorites')
                    && $context['deleted_ids'] === [$duplicateId];
            });

        $rows = DB::table('project_favorites')->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keptId, $rows->first()->id);
    }

    // ----------------------------------------------------- ticket_subscribers

    public function test_a_ticket_cannot_be_subscribed_to_twice_by_the_same_user(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->insertSubscription($user->id, $ticket->id);

        $this->expectException(QueryException::class);

        $this->insertSubscription($user->id, $ticket->id);
    }

    public function test_the_same_user_can_still_subscribe_to_several_tickets(): void
    {
        $user = User::factory()->create();
        $first = Ticket::factory()->create();
        $second = Ticket::factory()->create();

        $this->insertSubscription($user->id, $first->id);
        $this->insertSubscription($user->id, $second->id);

        $this->assertSame(2, DB::table('ticket_subscribers')->where('user_id', $user->id)->count());
    }

    public function test_pre_existing_subscription_duplicates_are_collapsed_and_logged(): void
    {
        $this->dropUniqueIndex('project_favorites', ['user_id', 'project_id']);
        $this->dropUniqueIndex('ticket_subscribers', ['user_id', 'ticket_id']);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $keptId = $this->insertSubscription($user->id, $ticket->id);
        $duplicateId = $this->insertSubscription($user->id, $ticket->id);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($duplicateId) {
                return str_contains($message, 'ticket_subscribers')
                    && $context['deleted_ids'] === [$duplicateId];
            });

        $rows = DB::table('ticket_subscribers')->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keptId, $rows->first()->id);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Simulates a database that still carries pre-migration duplicates by
     * removing the very index this migration adds.
     */
    private function dropUniqueIndex(string $table, array $columns): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($columns));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_24_000000_add_unique_index_to_favorite_and_subscriber_pivots.php');
        $migration->up();
    }

    private function insertFavorite(int $userId, int $projectId): int
    {
        return DB::table('project_favorites')->insertGetId([
            'user_id' => $userId,
            'project_id' => $projectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubscription(int $userId, int $ticketId): int
    {
        return DB::table('ticket_subscribers')->insertGetId([
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

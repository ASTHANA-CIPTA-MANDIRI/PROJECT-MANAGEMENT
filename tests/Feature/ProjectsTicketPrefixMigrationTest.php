<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsTicketPrefixMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_ticket_prefix_is_unique_at_the_database_level(): void
    {
        Project::factory()->create(['ticket_prefix' => 'DUP']);

        $this->expectException(QueryException::class);

        Project::factory()->create(['ticket_prefix' => 'DUP']);
    }

    public function test_down_actually_removes_the_unique_constraint(): void
    {
        $migration = require database_path('migrations/2023_04_10_123922_add_unique_ticket_prefix_to_projects_table.php');

        $migration->down();

        // With the constraint gone, a duplicate prefix no longer errors.
        Project::factory()->create(['ticket_prefix' => 'DUP']);
        Project::factory()->create(['ticket_prefix' => 'DUP']);
        $this->assertSame(2, Project::where('ticket_prefix', 'DUP')->count());

        // SQLite DDL is transactional, so RefreshDatabase's per-test
        // transaction rolls this dropUnique() back along with the data —
        // no manual restore needed for later tests in this run.
    }
}

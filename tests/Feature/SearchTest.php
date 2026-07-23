<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Tests use Scout's in-memory collection driver (see phpunit.xml).
    }

    // ------------------------------------------------------ service

    public function test_it_finds_matching_projects_the_user_can_access(): void
    {
        $user = User::factory()->create();
        $match = Project::factory()->create(['owner_id' => $user->id, 'name' => 'Peregrine Platform']);
        Project::factory()->create(['owner_id' => $user->id, 'name' => 'Unrelated thing']);

        $results = (new SearchService)->search($user, 'Peregrine');

        $this->assertCount(1, $results['projects']);
        $this->assertTrue($results['projects']->first()->is($match));
    }

    public function test_it_finds_matching_tickets_by_name_or_content(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $byName = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Kangaroo bug', 'content' => 'x']);
        $byContent = Ticket::factory()->create(['project_id' => $project->id, 'name' => 'y', 'content' => 'A wild Kangaroo appears']);
        Ticket::factory()->create(['project_id' => $project->id, 'name' => 'nope', 'content' => 'nope']);

        $results = (new SearchService)->search($user, 'Kangaroo');

        $this->assertCount(2, $results['tickets']);
        $this->assertEqualsCanonicalizing(
            [$byName->id, $byContent->id],
            $results['tickets']->pluck('id')->all()
        );
    }

    public function test_it_finds_matching_comments(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $match = TicketComment::factory()->create(['ticket_id' => $ticket->id, 'content' => 'Please review the Zephyr module']);
        TicketComment::factory()->create(['ticket_id' => $ticket->id, 'content' => 'unrelated']);

        $results = (new SearchService)->search($user, 'Zephyr');

        $this->assertCount(1, $results['comments']);
        $this->assertTrue($results['comments']->first()->is($match));
    }

    public function test_it_does_not_leak_results_from_inaccessible_projects(): void
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create(['name' => 'Secret Falcon project']); // not the user's
        Ticket::factory()->create(['project_id' => $foreign->id, 'name' => 'Falcon ticket']);

        $results = (new SearchService)->search($user, 'Falcon');

        $this->assertCount(0, $results['projects']);
        $this->assertCount(0, $results['tickets']);
    }

    public function test_a_project_member_can_search_the_project(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Osprey initiative']);
        $project->users()->attach($member->id, ['role' => 'employee']);

        $results = (new SearchService)->search($member, 'Osprey');

        $this->assertCount(1, $results['projects']);
    }

    public function test_a_blank_query_returns_nothing(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['owner_id' => $user->id]);

        $results = (new SearchService)->search($user, '   ');

        $this->assertCount(0, $results['projects']);
    }

    // ------------------------------------------------------ API endpoint

    public function test_search_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/search?q=test')->assertUnauthorized();
    }

    public function test_search_endpoint_requires_a_query(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/search')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_search_endpoint_returns_grouped_results(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create(['owner_id' => $user->id, 'name' => 'Meridian app']);
        Ticket::factory()->create(['project_id' => $project->id, 'name' => 'Meridian sync bug']);

        $this->getJson('/api/v1/search?q=Meridian')
            ->assertOk()
            ->assertJsonPath('query', 'Meridian')
            ->assertJsonPath('meta.projects_count', 1)
            ->assertJsonPath('meta.tickets_count', 1)
            ->assertJsonStructure([
                'query',
                'data' => [
                    'projects' => [['id', 'name']],
                    'tickets' => [['id', 'code', 'name']],
                    'comments',
                ],
                'meta' => ['projects_count', 'tickets_count', 'comments_count'],
            ]);
    }

    public function test_search_endpoint_scopes_to_the_callers_projects(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Project::factory()->create(['name' => 'Nimbus secret']); // someone else's

        $this->getJson('/api/v1/search?q=Nimbus')
            ->assertOk()
            ->assertJsonPath('meta.projects_count', 0);
    }
}

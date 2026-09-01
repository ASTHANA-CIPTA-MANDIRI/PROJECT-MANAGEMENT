<?php

namespace Tests\Feature\Filament;

use App\Jobs\ImportJiraTicketsJob;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The import job used to look a project up by name across the whole
 * installation: naming your Jira project after somebody else's was enough to
 * have your tickets - and the notifications that come with them - injected
 * into their project. It also wrote tickets straight to the database, took the
 * Jira key as a ticket prefix without the 3-character cap the rest of the app
 * enforces, and dereferenced the default lookups without checking them.
 */
class JiraImportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function seedDefaults(): void
    {
        ProjectStatus::factory()->default()->create();
        TicketStatus::factory()->default()->create();
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
    }

    private function jiraTicket(string $projectName, string $key, string $summary, $description = 'Imported'): object
    {
        return (object) [
            'fields' => (object) [
                'project' => (object) ['name' => $projectName, 'key' => $key],
                'summary' => $summary,
                'description' => $description,
            ],
        ];
    }

    // ------------------------------------------------------------- ownership

    public function test_it_does_not_import_into_a_same_named_project_of_another_user(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();
        $victimProject = Project::factory()->create(['name' => 'Alpha', 'ticket_prefix' => 'ALP']);

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALP', 'Injected')], $importer))->handle();

        $this->assertSame(0, $victimProject->tickets()->count(), 'the other tenant is untouched');

        $ticket = Ticket::sole();
        $this->assertNotSame($victimProject->id, $ticket->project_id);
        $this->assertSame($importer->id, $ticket->project->owner_id);
    }

    public function test_it_reuses_a_project_of_the_importer_with_the_same_name(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();
        $own = Project::factory()->create([
            'name' => 'Alpha', 'ticket_prefix' => 'ALP', 'owner_id' => $importer->id,
        ]);

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALP', 'Mine')], $importer))->handle();

        $this->assertSame(1, Project::count(), 'no duplicate project created');
        $this->assertSame($own->id, Ticket::sole()->project_id);
    }

    public function test_a_project_the_importer_is_a_member_of_is_reused(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();
        $shared = Project::factory()->create(['name' => 'Alpha', 'ticket_prefix' => 'ALP']);
        $shared->users()->attach($importer->id, ['role' => 'member']);

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALP', 'Ours')], $importer))->handle();

        $this->assertSame($shared->id, Ticket::sole()->project_id);
    }

    // ---------------------------------------------------------- ticket prefix

    public function test_a_long_jira_key_is_trimmed_to_three_characters(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALPHAKEY', 'First')], $importer))->handle();

        $this->assertSame('ALP', Project::sole()->ticket_prefix);
    }

    public function test_a_prefix_already_taken_does_not_break_the_import(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();
        Project::factory()->create(['name' => 'Someone else', 'ticket_prefix' => 'DUP']);

        (new ImportJiraTicketsJob([
            $this->jiraTicket('Alpha', 'DUP', 'First'),
            $this->jiraTicket('Beta', 'DUP', 'Second'),
        ], $importer))->handle();

        $prefixes = Project::orderBy('id')->pluck('ticket_prefix')->all();
        $this->assertSame(['DUP', 'DU1', 'DU2'], $prefixes);
        $this->assertSame(2, Ticket::count());
    }

    // ------------------------------------------------------- payload handling

    public function test_an_overlong_summary_is_trimmed_instead_of_failing_the_batch(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();

        (new ImportJiraTicketsJob([
            $this->jiraTicket('Alpha', 'ALP', str_repeat('a', 400)),
        ], $importer))->handle();

        $this->assertSame(255, mb_strlen(Ticket::sole()->name));
    }

    public function test_a_non_text_description_falls_back_to_a_placeholder(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();

        // Jira's newer API returns a document object rather than plain text.
        (new ImportJiraTicketsJob([
            $this->jiraTicket('Alpha', 'ALP', 'Summary', (object) ['type' => 'doc']),
        ], $importer))->handle();

        $this->assertSame(__('No content found in jira ticket'), Ticket::sole()->content);
    }

    /**
     * A fetch that timed out arrives here as null, and a malformed issue as an
     * object without a project. Neither may take the rest of the batch down -
     * and the importer has to be told what was left out.
     */
    public function test_an_unreadable_entry_is_skipped_and_reported(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();

        (new ImportJiraTicketsJob([
            $this->jiraTicket('Alpha', 'ALP', 'Good one'),
            null,
            (object) ['fields' => (object) ['summary' => 'No project at all']],
        ], $importer))->handle();

        $this->assertSame(1, Ticket::count(), 'the readable ticket is still imported');

        Notification::assertSentTo(
            $importer,
            DatabaseNotification::class,
            fn (DatabaseNotification $notification) => $this->notificationBody($notification, $importer) === __(
                ':imported jira tickets imported, :skipped could not be read and were skipped',
                ['imported' => 1, 'skipped' => 2],
            )
        );
    }

    /**
     * The body Filament stores for a database notification.
     */
    private function notificationBody(DatabaseNotification $notification, User $user): ?string
    {
        return $notification->toDatabase($user)['body'] ?? null;
    }

    public function test_a_fully_unreadable_batch_imports_nothing(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();

        (new ImportJiraTicketsJob([null, null], $importer))->handle();

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Project::count());
        Notification::assertSentTo($importer, DatabaseNotification::class);
    }

    public function test_a_missing_default_project_status_fails_cleanly(): void
    {
        // Deliberately no ProjectStatus at all.
        TicketStatus::factory()->default()->create();
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
        $importer = User::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALP', 'First')], $importer))->handle();
    }

    public function test_the_imported_ticket_gets_the_projects_own_default_status(): void
    {
        $this->seedDefaults();
        $importer = User::factory()->create();
        $project = Project::factory()->customStatuses()->create([
            'name' => 'Alpha', 'ticket_prefix' => 'ALP', 'owner_id' => $importer->id,
        ]);
        $own = TicketStatus::factory()->default()->forProject($project)->create();

        (new ImportJiraTicketsJob([$this->jiraTicket('Alpha', 'ALP', 'First')], $importer))->handle();

        $this->assertSame($own->id, Ticket::sole()->status_id);
    }
}

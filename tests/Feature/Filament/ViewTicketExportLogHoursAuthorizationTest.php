<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * "Export time logged" used to gate access with two different visible()
 * closures (one on the ActionGroup, a narrower one on the action itself) and
 * no check inside action() at all. canExportLogHours() on ViewTicket is now
 * the single condition behind both visible() calls and an abort_unless() at
 * the top of action() - the same pattern ViewTicketLogHoursAuthorizationTest
 * already covers for "Log time".
 */
class ViewTicketExportLogHoursAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function callExportLogHours(Ticket $ticket)
    {
        return Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('mountAction', 'exportLogHours')
            ->call('callMountedAction');
    }

    /**
     * ExcelFake exposes no "nothing downloaded" assertion, so this reads its
     * internal $downloads array directly (mirroring assertDownloaded()'s own
     * approach in the package).
     */
    private function assertNoDownloadHappened(): void
    {
        $downloads = new \ReflectionProperty(\Maatwebsite\Excel\Fakes\ExcelFake::class, 'downloads');
        $downloads->setAccessible(true);

        $this->assertSame([], $downloads->getValue(Excel::getFacadeRoot()));
    }

    /**
     * The project's owner can view the ticket (Project::isAccessibleBy()
     * matches owner_id) without being one of its watchers: watchers are the
     * project's *members* plus the ticket's owner/responsible, and this user
     * is deliberately none of those - only ever attached as the project's
     * owner_id.
     */
    private function projectOwnerOutsiderViewing(Ticket $ticket): User
    {
        $reader = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket->project->update(['owner_id' => $reader->id]);

        $this->actingAs($reader);

        return $reader;
    }

    public function test_the_export_button_is_hidden_from_a_watcher_free_outsider(): void
    {
        $ticket = Ticket::factory()->create();
        $this->projectOwnerOutsiderViewing($ticket);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertDontSeeHtml("mountAction('exportLogHours')");
    }

    public function test_an_outsider_cannot_export_log_hours_even_calling_the_action_directly(): void
    {
        Excel::fake();
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->create(['ticket_id' => $ticket->id, 'user_id' => User::factory()->create()->id]);
        $this->projectOwnerOutsiderViewing($ticket);

        $this->callExportLogHours($ticket)->assertSuccessful();

        $this->assertNoDownloadHappened();
    }

    public function test_the_ticket_owner_can_export_log_hours_even_without_logged_time(): void
    {
        Excel::fake();
        $owner = $this->userWithPermissions(['List tickets', 'View ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($owner->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $owner->id]);

        $this->actingAs($owner);

        $this->callExportLogHours($ticket)->assertSuccessful();

        Excel::assertDownloaded('time_'.str_replace('-', '_', $ticket->code).'.csv');
    }

    public function test_a_watcher_can_export_once_hours_are_logged(): void
    {
        Excel::fake();
        $watcher = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket = Ticket::factory()->create();
        $ticket->project->users()->attach($watcher->id, ['role' => 'employee']);
        TicketHour::factory()->create(['ticket_id' => $ticket->id, 'user_id' => User::factory()->create()->id]);

        $this->actingAs($watcher);

        $this->callExportLogHours($ticket)->assertSuccessful();

        Excel::assertDownloaded('time_'.str_replace('-', '_', $ticket->code).'.csv');
    }

    public function test_a_watcher_cannot_export_without_any_logged_time(): void
    {
        Excel::fake();
        $watcher = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket = Ticket::factory()->create();
        $ticket->project->users()->attach($watcher->id, ['role' => 'employee']);

        $this->actingAs($watcher);

        $this->callExportLogHours($ticket)->assertSuccessful();

        $this->assertNoDownloadHappened();
    }
}

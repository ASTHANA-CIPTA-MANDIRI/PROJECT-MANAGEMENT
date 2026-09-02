<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Forms\TicketForm;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Models\Epic;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Phase 3B priority finding: the Filament admin Ticket form (unlike the
 * Road Map's IssueForm, which was already hardened) trusted project_id –
 * and every value derived from it (epic options, custom status options,
 * default status, the "related ticket" list) – straight off Livewire state.
 *
 * Empirically confirmed before this fix: a user holding only "Create
 * ticket"+"List tickets" (no membership of the target project at all)
 * could create a real Ticket row in an arbitrary foreign project purely by
 * setting project_id in the Livewire payload, since neither
 * TicketPolicy::create() (a class-level check, no record to inspect yet)
 * nor CreateTicket::handleRecordCreation() re-validated it. This suite
 * proves that gap is closed (TicketForm::assertAccessibleProject(), called
 * from CreateTicket/EditTicket) and that the option lists that fed on the
 * same untrusted project_id no longer leak another tenant's epic/status/
 * ticket names (TicketForm::accessibleProject()).
 */
class TicketFormTenantScopingTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function seedLookups(): void
    {
        TicketStatus::factory()->default()->create(['project_id' => null]);
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
    }

    // ------------------------------------------------------------- create

    public function test_creating_a_ticket_in_a_foreign_project_is_rejected(): void
    {
        $user = $this->userWithPermissions(['Create ticket', 'List tickets']);
        $foreignProject = Project::factory()->create(); // $user has no access at all
        $this->seedLookups();
        $status = TicketStatus::query()->sole();
        $type = TicketType::query()->sole();
        $priority = TicketPriority::query()->sole();
        $this->actingAs($user);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'project_id' => $foreignProject->id,
                'name' => 'Sneaky ticket',
                'content' => 'Body',
                'owner_id' => $user->id,
                'status_id' => $status->id,
                'type_id' => $type->id,
                'priority_id' => $priority->id,
            ])
            ->call('create');

        $this->assertSame(0, Ticket::count());
    }

    public function test_creating_a_ticket_in_an_accessible_project_succeeds(): void
    {
        $user = $this->userWithPermissions(['Create ticket', 'List tickets']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $this->seedLookups();
        $status = TicketStatus::query()->sole();
        $type = TicketType::query()->sole();
        $priority = TicketPriority::query()->sole();
        $this->actingAs($user);

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'project_id' => $project->id,
                'name' => 'Legit ticket',
                'content' => 'Body',
                'owner_id' => $user->id,
                'status_id' => $status->id,
                'type_id' => $type->id,
                'priority_id' => $priority->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::sole();
        $this->assertSame($project->id, $ticket->project_id);
    }

    // --------------------------------------------------------------- edit

    public function test_re_parenting_a_ticket_into_a_foreign_project_is_rejected(): void
    {
        $user = $this->userWithPermissions(['View ticket', 'Update ticket', 'List tickets']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $foreignProject = Project::factory()->create();
        $this->seedLookups();
        $status = TicketStatus::query()->sole();
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'status_id' => $status->id,
        ]);
        $this->actingAs($user);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditTicket::class, ['record' => $ticket->id])
            ->fillForm(['project_id' => $foreignProject->id])
            ->call('save');

        $this->assertSame($project->id, $ticket->fresh()->project_id);
    }

    // -------------------------------------------------------- option leaks

    public function test_tampering_with_project_id_does_not_leak_a_foreign_epic(): void
    {
        $user = $this->userWithPermissions(['Create ticket', 'List tickets']);
        $foreignProject = Project::factory()->create();
        $foreignEpic = Epic::factory()->create([
            'project_id' => $foreignProject->id,
            'name' => 'Secret Epic Org B',
        ]);
        $this->actingAs($user);

        Livewire::test(CreateTicket::class)
            ->set('data.project_id', $foreignProject->id)
            ->assertDontSee($foreignEpic->name);
    }

    public function test_tampering_with_project_id_does_not_leak_a_foreign_custom_status(): void
    {
        $user = $this->userWithPermissions(['Create ticket', 'List tickets']);
        $foreignProject = Project::factory()->customStatuses()->create();
        $foreignStatus = TicketStatus::factory()->create([
            'project_id' => $foreignProject->id,
            'name' => 'Secret Status Org B',
        ]);
        $this->actingAs($user);

        Livewire::test(CreateTicket::class)
            ->set('data.project_id', $foreignProject->id)
            ->assertDontSee($foreignStatus->name);
    }

    public function test_the_related_ticket_picker_excludes_foreign_projects_tickets(): void
    {
        // Exercised directly rather than through the Livewire-rendered
        // Repeater (which starts collapsed with zero items and never
        // renders its nested Select at all until a row is added) - this is
        // the exact query TicketForm's "Related ticket" Select options()
        // callback runs.
        $user = $this->userWithPermissions(['Create ticket', 'List tickets']);
        $ownProject = Project::factory()->create(['owner_id' => $user->id]);
        $ownTicket = Ticket::factory()->create([
            'project_id' => $ownProject->id,
            'name' => 'Own Visible Ticket',
        ]);
        $foreignProject = Project::factory()->create();
        $foreignTicket = Ticket::factory()->create([
            'project_id' => $foreignProject->id,
            'name' => 'Foreign Hidden Ticket',
        ]);
        $this->actingAs($user);

        $options = TicketForm::relatedTicketOptions();

        $this->assertArrayHasKey($ownTicket->id, $options);
        $this->assertArrayNotHasKey($foreignTicket->id, $options);
    }
}

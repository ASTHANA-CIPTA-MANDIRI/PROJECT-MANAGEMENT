<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Ticket\Attachments;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * The attachments component used to run without a single authorization check:
 * the parent page only enforces TicketPolicy::view(), so anyone who could open
 * a ticket could upload files to it and delete other people's attachments -
 * and because Media Library overwrites same-named files, replace them silently.
 *
 * Reading the list stays on view() - the same rule media.show applies to the
 * files themselves (see MediaAuthorizationTest) - while uploading and deleting
 * now take update().
 */
class TicketAttachmentsAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    /**
     * A project member with the given permissions, plus a ticket in it.
     *
     * @param  array<int, string>  $permissions
     * @return array{0: \App\Models\User, 1: Ticket}
     */
    private function memberWith(array $permissions): array
    {
        $user = $this->userWithPermissions($permissions);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user);

        return [$user, $ticket];
    }

    /**
     * Switch the acting user to a member of the ticket's project who may read
     * it but not change it.
     */
    private function actAsReadOnlyMemberOf(Ticket $ticket): void
    {
        $reader = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket->project->users()->attach($reader->id, ['role' => 'employee']);

        $this->actingAs($reader);
    }

    // ------------------------------------------------------- reading is view()

    public function test_a_read_only_member_can_still_see_the_attachment_list(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket']);
        $ticket->addMediaFromString('shared spec')
            ->usingFileName('spec.txt')
            ->usingName('Shared spec')
            ->toMediaCollection();

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->assertSuccessful()
            ->assertSee('Shared spec');
    }

    public function test_the_upload_form_is_hidden_from_a_read_only_member(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket']);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->assertDontSeeHtml('wire:submit.prevent="perform"');
    }

    public function test_the_upload_form_is_shown_to_a_member_who_may_update(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket', 'Update ticket']);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->assertSeeHtml('wire:submit.prevent="perform"');
    }

    /**
     * view() is object-level too, so an outsider is refused outright.
     */
    public function test_an_outsider_cannot_open_the_component_at_all(): void
    {
        $stranger = $this->userWithPermissions(['List tickets', 'View ticket']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($stranger);

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->assertForbidden();
    }

    // ------------------------------------------------------ writing is update()

    /**
     * The next two mount as somebody allowed and only then switch to the
     * read-only member, which is what a crafted Livewire update request looks
     * like: no mount(), straight to an action. Hiding the form and the delete
     * button is UX; these are the checks that actually stop the write.
     */
    public function test_a_read_only_member_cannot_upload_an_attachment(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket', 'Update ticket']);
        $component = Livewire::test(Attachments::class, ['ticket' => $ticket]);

        $this->actAsReadOnlyMemberOf($ticket);

        $component->call('perform')->assertForbidden();

        $this->assertSame(0, $ticket->media()->count());
    }

    public function test_a_read_only_member_cannot_delete_an_existing_attachment(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket', 'Update ticket']);
        $media = $ticket->addMediaFromString('someone elses file')
            ->usingFileName('report.txt')
            ->toMediaCollection();
        $component = Livewire::test(Attachments::class, ['ticket' => $ticket]);

        $this->actAsReadOnlyMemberOf($ticket);

        // No 403 here: hiding the action already makes Filament refuse to
        // mount or call it, so the request is dropped before the closure's own
        // abort_unless() runs. What matters is that the file survives.
        $component->call('mountTableAction', 'delete', $media->getKey())
            ->call('callMountedTableAction');

        $this->assertSame(1, $ticket->media()->count());
        $this->assertDatabaseHas('media', ['id' => $media->getKey()]);
    }

    public function test_the_delete_button_is_hidden_from_a_read_only_member(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket']);
        $media = $ticket->addMediaFromString('shared spec')
            ->usingFileName('spec.txt')
            ->toMediaCollection();

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->assertDontSeeHtml("mountTableAction('delete', '".$media->getKey()."')");
    }

    // ----------------------------------------------------------- legit paths

    public function test_a_member_who_may_update_can_delete_an_attachment(): void
    {
        [, $ticket] = $this->memberWith(['List tickets', 'View ticket', 'Update ticket']);
        $media = $ticket->addMediaFromString('my own file')
            ->usingFileName('report.txt')
            ->toMediaCollection();

        Livewire::test(Attachments::class, ['ticket' => $ticket])
            ->call('mountTableAction', 'delete', $media->getKey())
            ->call('callMountedTableAction')
            ->assertSuccessful();

        $this->assertSame(0, $ticket->media()->count());
    }
}

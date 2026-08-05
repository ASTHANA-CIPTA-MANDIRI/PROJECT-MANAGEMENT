<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * Ticket attachments and project covers used to be served straight off the
 * "public" disk symlink - anyone who could guess/enumerate a media id could
 * download it, regardless of TicketPolicy/ProjectPolicy. They now go through
 * the authenticated media.show route (MediaController), which must run the
 * same view() check the resource pages already enforce.
 */
class MediaAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_guests_are_redirected_to_login_instead_of_streaming_the_file(): void
    {
        $ticket = Ticket::factory()->create();
        $media = $ticket->addMediaFromString('secret file contents')
            ->usingFileName('secret.txt')
            ->toMediaCollection();

        $this->get(route('media.show', $media))->assertRedirect(route('login'));
    }

    public function test_an_unrelated_user_cannot_download_a_ticket_attachment(): void
    {
        $stranger = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create();
        $media = $ticket->addMediaFromString('secret file contents')
            ->usingFileName('secret.txt')
            ->toMediaCollection();

        $this->actingAs($stranger)
            ->get(route('media.show', $media))
            ->assertForbidden();
    }

    public function test_the_ticket_owner_can_download_their_attachment(): void
    {
        $owner = $this->userWithPermissions(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $media = $ticket->addMediaFromString('secret file contents')
            ->usingFileName('secret.txt')
            ->toMediaCollection();

        $this->actingAs($owner)
            ->get(route('media.show', $media))
            ->assertSuccessful();
    }

    public function test_a_project_member_can_download_the_ticket_attachment(): void
    {
        $member = $this->userWithPermissions(['View ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $media = $ticket->addMediaFromString('secret file contents')
            ->usingFileName('secret.txt')
            ->toMediaCollection();

        $this->actingAs($member)
            ->get(route('media.show', $media))
            ->assertSuccessful();
    }

    public function test_an_unrelated_user_cannot_download_the_project_cover(): void
    {
        $stranger = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create();
        $media = $project->addMediaFromString('cover bytes')
            ->usingFileName('cover.png')
            ->toMediaCollection();

        $this->actingAs($stranger)
            ->get(route('media.show', $media))
            ->assertForbidden();
    }

    public function test_the_project_owner_can_download_the_project_cover(): void
    {
        $owner = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $media = $project->addMediaFromString('cover bytes')
            ->usingFileName('cover.png')
            ->toMediaCollection();

        $this->actingAs($owner)
            ->get(route('media.show', $media))
            ->assertSuccessful();
    }

    public function test_authorization_is_still_checked_without_the_view_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        $media = $ticket->addMediaFromString('secret file contents')
            ->usingFileName('secret.txt')
            ->toMediaCollection();

        // Owns the ticket, but TicketPolicy::view() also requires the
        // "View ticket" permission - ownership alone isn't enough.
        $this->actingAs($user)
            ->get(route('media.show', $media))
            ->assertForbidden();
    }
}

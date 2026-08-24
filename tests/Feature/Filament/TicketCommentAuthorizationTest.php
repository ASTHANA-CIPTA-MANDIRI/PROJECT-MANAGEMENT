<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * doDeleteComment() is a public Livewire listener and submitComment() reads the
 * client-controlled $selectedCommentId, so both take a comment id straight from
 * the browser. They must only ever touch a comment that belongs to the ticket
 * being viewed AND to the current user (or a project administrator).
 */
class TicketCommentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function makeViewer(): User
    {
        $role = Role::create(['name' => 'Ticket viewer '.uniqid()]);
        $role->givePermissionTo([
            Permission::firstOrCreate(['name' => 'List tickets']),
            Permission::firstOrCreate(['name' => 'View ticket']),
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ------------------------------------------------------------- attacker

    public function test_a_user_cannot_delete_a_comment_from_another_project(): void
    {
        $attacker = $this->makeViewer();
        $ownTicket = Ticket::factory()->create(['owner_id' => $attacker->id]);
        $victimComment = TicketComment::factory()->create();

        $this->actingAs($attacker);

        Livewire::test(ViewTicket::class, ['record' => $ownTicket->getRouteKey()])
            ->call('doDeleteComment', $victimComment->id);

        $this->assertNotSoftDeleted($victimComment);
    }

    public function test_a_user_cannot_overwrite_a_comment_from_another_project(): void
    {
        $attacker = $this->makeViewer();
        $ownTicket = Ticket::factory()->create(['owner_id' => $attacker->id]);
        $victimComment = TicketComment::factory()->create(['content' => '<p>Original</p>']);

        $this->actingAs($attacker);

        Livewire::test(ViewTicket::class, ['record' => $ownTicket->getRouteKey()])
            ->set('selectedCommentId', $victimComment->id)
            ->set('comment', '<script>alert(1)</script>PWNED')
            ->call('submitComment');

        $this->assertStringContainsString('Original', $victimComment->fresh()->content);
        $this->assertStringNotContainsString('PWNED', $victimComment->fresh()->content);
    }

    public function test_a_user_cannot_edit_a_comment_of_another_member_on_the_same_ticket(): void
    {
        $attacker = $this->makeViewer();
        $ticket = Ticket::factory()->create(['owner_id' => $attacker->id]);
        $othersComment = TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'content' => '<p>Original</p>',
        ]);

        $this->actingAs($attacker);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('editComment', $othersComment->id)
            ->assertSet('selectedCommentId', null)
            ->set('selectedCommentId', $othersComment->id)
            ->set('comment', '<p>Hijacked</p>')
            ->call('submitComment');

        $this->assertStringContainsString('Original', $othersComment->fresh()->content);
    }

    // ---------------------------------------------------------- legit paths

    public function test_the_author_can_delete_their_own_comment(): void
    {
        $author = $this->makeViewer();
        $ticket = Ticket::factory()->create(['owner_id' => $author->id]);
        $comment = TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($author);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('doDeleteComment', $comment->id);

        $this->assertSoftDeleted($comment);
    }

    public function test_the_author_can_edit_their_own_comment_and_the_content_is_sanitized(): void
    {
        $author = $this->makeViewer();
        $ticket = Ticket::factory()->create(['owner_id' => $author->id]);
        $comment = TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'content' => '<p>Original</p>',
        ]);

        $this->actingAs($author);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->set('selectedCommentId', $comment->id)
            ->set('comment', '<p>Updated</p><script>alert(1)</script>')
            ->call('submitComment');

        $stored = $comment->fresh()->content;
        $this->assertStringContainsString('Updated', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_a_project_administrator_can_moderate_a_members_comment(): void
    {
        $admin = $this->makeViewer();
        $project = Project::factory()->create();
        $project->users()->attach($admin->id, ['role' => 'administrator']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id]);

        $this->actingAs($admin);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('doDeleteComment', $comment->id);

        $this->assertSoftDeleted($comment);
    }

    /**
     * isAdministrator() must read the managing-role name from config, the
     * same source Project::isManageableBy() uses, instead of a literal
     * 'administrator' string. Otherwise renaming the role in config would
     * silently disable comment moderation without any test noticing.
     */
    public function test_moderation_follows_the_configured_managing_role_not_a_literal_string(): void
    {
        config(['system.projects.affectations.roles.can_manage' => 'lead']);

        $lead = $this->makeViewer();
        $project = Project::factory()->create();
        $project->users()->attach($lead->id, ['role' => 'lead']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id]);

        $this->actingAs($lead);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->call('doDeleteComment', $comment->id);

        $this->assertSoftDeleted($comment);
    }
}

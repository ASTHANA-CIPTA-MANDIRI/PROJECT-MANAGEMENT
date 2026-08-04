<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\TicketCommented;
use App\Notifications\UserMentioned;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_mentioning_a_project_member_notifies_them(): void
    {
        $member = User::factory()->create(['name' => 'Jane Roe']);
        $author = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach([$member->id, $author->id], ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'content' => '<p>Hey @Jane Roe please take a look</p>',
        ]);

        Notification::assertSentTo($member, UserMentioned::class);
    }

    public function test_a_watcher_who_is_not_mentioned_gets_the_generic_notification(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Person']);
        $author = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'content' => '<p>No mention here</p>',
        ]);

        Notification::assertSentTo($owner, TicketCommented::class);
        Notification::assertNotSentTo($owner, UserMentioned::class);
    }

    public function test_the_author_is_not_notified_of_mentioning_themselves(): void
    {
        $author = User::factory()->create(['name' => 'Self Mentioner']);
        $project = Project::factory()->create();
        $project->users()->attach($author->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'content' => '<p>Note to @Self Mentioner</p>',
        ]);

        Notification::assertNotSentTo($author, UserMentioned::class);
    }

    public function test_a_shorter_name_is_not_shadowed_by_a_longer_mention(): void
    {
        $shortName = User::factory()->make(['name' => 'John']);
        $longName = User::factory()->make(['name' => 'John Doe']);

        $mentioned = Mentions::mentioned(
            'Ping @John Doe about this',
            collect([$shortName, $longName])
        );

        $this->assertCount(1, $mentioned);
        $this->assertSame('John Doe', $mentioned->first()->name);
    }

    public function test_highlight_wraps_a_mentioned_name(): void
    {
        $user = User::factory()->make(['name' => 'Jane Roe']);

        $html = Mentions::highlight('<p>Hi @Jane Roe</p>', collect([$user]));

        $this->assertStringContainsString('text-primary-600', $html);
        $this->assertStringContainsString('@Jane Roe', $html);
    }

    public function test_highlight_leaves_plain_text_untouched(): void
    {
        $user = User::factory()->make(['name' => 'Jane Roe']);

        $html = Mentions::highlight('<p>No mentions here</p>', collect([$user]));

        $this->assertSame('<p>No mentions here</p>', $html);
    }

    // ------------------------------------------------- duplicate-name safety

    public function test_an_id_tagged_mention_notifies_the_exact_user_even_with_a_duplicate_name(): void
    {
        $author = User::factory()->create();
        $project = Project::factory()->create();
        $jane1 = User::factory()->create(['name' => 'Jane Roe']);
        $jane2 = User::factory()->create(['name' => 'Jane Roe']);
        $project->users()->attach([$jane1->id, $jane2->id, $author->id], ['role' => 'employee']);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'content' => "<p>Hey @Jane Roe#{$jane2->id} please take a look</p>",
        ]);

        Notification::assertSentTo($jane2, UserMentioned::class);
        Notification::assertNotSentTo($jane1, UserMentioned::class);
    }

    public function test_mentioned_resolves_an_id_tagged_mention_by_id_not_name(): void
    {
        $jane1 = User::factory()->create(['name' => 'Jane Roe']);
        $jane2 = User::factory()->create(['name' => 'Jane Roe']);

        $mentioned = Mentions::mentioned(
            "Ping @Jane Roe#{$jane2->id} about this",
            collect([$jane1, $jane2])
        );

        $this->assertCount(1, $mentioned);
        $this->assertSame($jane2->id, $mentioned->first()->id);
    }

    public function test_highlight_hides_the_id_suffix_and_shows_only_the_name(): void
    {
        $user = User::factory()->create(['name' => 'Jane Roe']);

        $html = Mentions::highlight("<p>Hi @Jane Roe#{$user->id}</p>", collect([$user]));

        $this->assertStringNotContainsString('#'.$user->id, $html);
        $this->assertStringContainsString('text-primary-600', $html);
        $this->assertStringContainsString('@Jane Roe', $html);
    }

    public function test_strip_ids_removes_the_hidden_marker(): void
    {
        $html = Mentions::stripIds('<p>Hi @Jane Roe#42 there</p>');

        $this->assertSame('<p>Hi @Jane Roe there</p>', $html);
    }

    public function test_editing_a_comment_loads_content_with_the_id_marker_stripped(): void
    {
        $owner = User::factory()->create(['name' => 'Jane Roe']);
        $role = Role::create(['name' => 'Ticket viewer']);
        $role->givePermissionTo([
            Permission::firstOrCreate(['name' => 'List tickets']),
            Permission::firstOrCreate(['name' => 'View ticket']),
        ]);
        $owner->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $comment = TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'content' => "<p>Hi @Jane Roe#{$owner->id}</p>",
        ]);

        $this->actingAs($owner);

        \Livewire\Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\ViewTicket::class,
            ['record' => $ticket->getRouteKey()]
        )
            ->call('editComment', $comment->id)
            ->assertSet('comment', '<p>Hi @Jane Roe</p>');
    }
}

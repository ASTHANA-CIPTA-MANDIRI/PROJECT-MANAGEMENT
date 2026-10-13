<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Inbox;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\ChatDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ChatInboxTest extends TestCase
{
    use RefreshDatabase;

    private function orgWith(User ...$users): Organization
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(7)]);

        foreach ($users as $user) {
            $organization->users()->attach($user->id, ['role' => 'member']);
        }

        return $organization;
    }

    public function test_org_members_can_message_each_other_and_unread_state_follows(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create(['name' => 'Rekan Satu']);
        $this->orgWith($me, $mate);

        $this->actingAs($me);
        Livewire::test(Inbox::class)
            ->call('openChat', $mate->id)
            ->set('draft', 'Halo **tim**')
            ->call('sendMessage')
            ->assertSet('draft', '')
            ->assertSee('<strong>tim</strong>', false);

        $conversation = Conversation::findBetween($me->id, $mate->id);
        $this->assertSame('Halo **tim**', $conversation->last_message_preview);
        $this->assertFalse($conversation->isUnreadFor($me));
        $this->assertTrue($conversation->isUnreadFor($mate));
        $this->assertSame(1, Conversation::unreadFor($mate)->count());
        $this->assertSame(0, Conversation::unreadFor($me)->count());

        $this->actingAs($mate);
        Livewire::test(Inbox::class)->assertSee('Halo')->call('openChat', $me->id);

        $this->assertFalse($conversation->fresh()->isUnreadFor($mate));
        $this->assertSame(0, Conversation::unreadFor($mate)->count());
    }

    public function test_people_outside_org_and_projects_cannot_be_messaged(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $this->orgWith($me);
        $this->orgWith($stranger);

        $this->actingAs($me);
        Livewire::test(Inbox::class)
            ->call('openChat', $stranger->id)
            ->assertSet('activeUserId', null)
            ->set('activeUserId', $stranger->id)
            ->set('draft', 'nyelonong')
            ->call('sendMessage');

        $this->assertSame(0, Message::count());
        $this->assertFalse(ChatDirectory::canMessage($me, $stranger->id));
        $this->assertFalse(ChatDirectory::canMessage($me, $me->id));
    }

    public function test_project_members_without_a_shared_organization_can_chat(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $me->id]);
        $project->users()->attach($mate->id, ['role' => 'member']);

        $this->assertTrue(ChatDirectory::canMessage($me, $mate->id));
        $this->assertTrue(ChatDirectory::canMessage($mate, $me->id));
    }

    public function test_locked_organization_loses_org_contacts(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $organization = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
        $organization->users()->attach([$me->id => ['role' => 'member'], $mate->id => ['role' => 'member']]);

        $this->assertFalse(ChatDirectory::canMessage($me, $mate->id));

        $this->actingAs($me);
        Livewire::test(Inbox::class)->call('openChat', $mate->id)->assertSet('activeUserId', null);
    }

    public function test_conversation_pair_is_unique_and_history_is_paginated(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $this->orgWith($me, $mate);

        $this->assertSame(Conversation::between($me->id, $mate->id)->id, Conversation::between($mate->id, $me->id)->id);

        $conversation = Conversation::between($me->id, $mate->id);
        foreach (range(1, 35) as $i) {
            Message::create(['conversation_id' => $conversation->id, 'user_id' => $mate->id, 'body' => "pesan-$i"]);
        }

        $this->actingAs($me);
        Livewire::test(Inbox::class)
            ->call('openChat', $mate->id)
            ->assertSee('pesan-35')
            ->assertDontSee('pesan-1<')
            ->assertSee('Muat pesan sebelumnya')
            ->call('loadOlder')
            ->assertSee('pesan-1')
            ->assertDontSee('Muat pesan sebelumnya');
    }

    public function test_sending_is_rate_limited_and_validated(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $this->orgWith($me, $mate);

        $this->actingAs($me);
        $component = Livewire::test(Inbox::class)->call('openChat', $mate->id);
        $component->set('draft', '')->call('sendMessage')->assertHasErrors('draft');

        foreach (range(1, 31) as $i) {
            $component->set('draft', "m$i")->call('sendMessage');
        }

        $this->assertSame(30, Message::count());
    }

    public function test_old_messages_are_pruned_only_when_retention_is_enabled(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $conversation = Conversation::between($me->id, $mate->id);
        $old = Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'body' => 'lama']);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();
        Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'body' => 'baru']);

        config(['app.chat_retention_days' => 0]);
        $this->artisan('model:prune', ['--model' => Message::class]);
        $this->assertSame(2, Message::count());

        config(['app.chat_retention_days' => 365]);
        $this->artisan('model:prune', ['--model' => Message::class]);
        $this->assertSame(1, Message::count());
    }

    public function test_stale_threads_are_hidden_from_list_and_unread_badge(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $gone = User::factory()->create(['name' => 'Tak Terjangkau']);
        $this->orgWith($me, $mate);

        // $gone is not reachable (no shared org/project) but once wrote to $me.
        $stale = Conversation::between($me->id, $gone->id);
        Message::create(['conversation_id' => $stale->id, 'user_id' => $gone->id, 'body' => 'dulu']);
        $stale->forceFill(['last_message_at' => now(), 'last_sender_id' => $gone->id, 'last_message_preview' => 'dulu'])->saveQuietly();

        $this->assertSame(0, ChatDirectory::unreadCount($me));

        $this->actingAs($me);
        Livewire::test(Inbox::class)->assertDontSee('Tak Terjangkau')->assertSee($mate->name);
    }

    public function test_message_in_the_same_second_as_the_read_marker_stays_unread(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $conversation = Conversation::between($me->id, $mate->id);
        $now = now();

        $conversation->forceFill(['last_message_at' => $now->copy()->addMicroseconds(500), 'last_sender_id' => $mate->id])->saveQuietly();
        $conversation->forceFill([($conversation->user_a_id === $me->id ? 'a_read_at' : 'b_read_at') => $now])->saveQuietly();

        $this->assertTrue($conversation->fresh()->isUnreadFor($me));
    }

    public function test_message_limit_is_clamped_server_side(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $this->orgWith($me, $mate);
        $conversation = Conversation::between($me->id, $mate->id);
        foreach (range(1, 40) as $i) {
            Message::create(['conversation_id' => $conversation->id, 'user_id' => $mate->id, 'body' => "m-$i"]);
        }

        $this->actingAs($me);
        Livewire::test(Inbox::class)->call('openChat', $mate->id)->set('messageLimit', 999999999)->assertSee('m-1<', false);
    }

    public function test_between_recovers_when_the_row_already_exists_from_a_racing_request(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $first = Conversation::between($a->id, $b->id);
        $second = Conversation::between($b->id, $a->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Conversation::count());
    }

    private function chatPair(): array
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $this->orgWith($me, $mate);

        return [$me, $mate];
    }

    public function test_images_and_files_can_be_sent_and_images_are_shrunk(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();

        $this->actingAs($me);
        Livewire::test(Inbox::class)
            ->call('openChat', $mate->id)
            ->set('upload', UploadedFile::fake()->image('foto besar.png', 3200, 2400))
            ->call('sendMessage')
            ->assertSet('upload', null)
            ->set('upload', UploadedFile::fake()->create('laporan.pdf', 120, 'application/pdf'))
            ->set('draft', 'Ini laporannya')
            ->call('sendMessage');

        $this->assertSame(2, MessageAttachment::count());

        $image = MessageAttachment::where('is_image', true)->firstOrFail();
        Storage::disk('local')->assertExists($image->path);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($image->path));
        $this->assertLessThanOrEqual(1600, max($width, $height));

        $file = MessageAttachment::where('is_image', false)->firstOrFail();
        $this->assertSame('laporan.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_unsafe_types_and_oversized_images_are_rejected(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();

        $this->actingAs($me);
        Livewire::test(Inbox::class)
            ->call('openChat', $mate->id)
            ->set('upload', UploadedFile::fake()->create('halaman.html', 5, 'text/html'))
            ->call('sendMessage')
            ->assertHasErrors('upload')
            ->set('upload', UploadedFile::fake()->create('program.exe', 5, 'application/x-msdownload'))
            ->call('sendMessage')
            ->assertHasErrors('upload')
            ->set('upload', UploadedFile::fake()->image('raksasa.jpg', 800, 800)->size(6000))
            ->call('sendMessage')
            ->assertHasErrors('upload');

        $this->assertSame(0, MessageAttachment::count());
        $this->assertSame(0, Message::count());
    }

    public function test_attachment_download_is_limited_to_the_conversation_participants(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();
        $outsider = User::factory()->create();

        $this->actingAs($me);
        Livewire::test(Inbox::class)->call('openChat', $mate->id)->set('upload', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->call('sendMessage');
        $attachment = MessageAttachment::firstOrFail();

        $this->get(route('chat.attachment', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($mate)->get(route('chat.attachment', $attachment))->assertOk();
        $this->actingAs($outsider)->get(route('chat.attachment', $attachment))->assertForbidden();
        auth()->logout();
        $this->get(route('chat.attachment', $attachment))->assertRedirect();

        $attachment->purge();
        $this->actingAs($me)->get(route('chat.attachment', $attachment))->assertNotFound();
    }

    public function test_expired_attachments_are_purged_but_the_message_stays(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();
        $conversation = Conversation::between($me->id, $mate->id);
        $message = Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'body' => '']);

        Storage::disk('local')->put('chat/x/old.pdf', 'x');
        Storage::disk('local')->put('chat/x/new.pdf', 'x');
        $old = $message->attachments()->create(['conversation_id' => $conversation->id, 'path' => 'chat/x/old.pdf', 'original_name' => 'old.pdf', 'mime' => 'application/pdf', 'size' => 1, 'is_image' => false]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $new = $message->attachments()->create(['conversation_id' => $conversation->id, 'path' => 'chat/x/new.pdf', 'original_name' => 'new.pdf', 'mime' => 'application/pdf', 'size' => 1, 'is_image' => false]);

        config(['system.chat.attachment_days' => 0]);
        $this->assertSame(0, MessageAttachment::purgeExpired());

        config(['system.chat.attachment_days' => 30]);
        $this->assertSame(1, MessageAttachment::purgeExpired());

        Storage::disk('local')->assertMissing('chat/x/old.pdf');
        Storage::disk('local')->assertExists('chat/x/new.pdf');
        $this->assertNotNull($old->fresh()->purged_at);
        $this->assertNull($new->fresh()->purged_at);
        $this->assertNotNull($message->fresh());

        $this->actingAs($me);
        Livewire::test(Inbox::class)->call('openChat', $mate->id)->assertSee('Lampiran sudah kedaluwarsa');
    }

    public function test_pruning_a_message_deletes_its_files(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();
        $conversation = Conversation::between($me->id, $mate->id);
        $message = Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'body' => 'lama']);
        $message->forceFill(['created_at' => now()->subDays(400)])->save();
        Storage::disk('local')->put('chat/y/file.pdf', 'x');
        $message->attachments()->create(['conversation_id' => $conversation->id, 'path' => 'chat/y/file.pdf', 'original_name' => 'file.pdf', 'mime' => 'application/pdf', 'size' => 1, 'is_image' => false]);

        config(['app.chat_retention_days' => 365]);
        $this->artisan('model:prune', ['--model' => Message::class]);

        $this->assertSame(0, Message::count());
        Storage::disk('local')->assertMissing('chat/y/file.pdf');
    }

    public function test_corrupt_and_pixel_flood_images_are_rejected_cleanly(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();

        $this->actingAs($me);
        $component = Livewire::test(Inbox::class)->call('openChat', $mate->id);

        // Valid PNG signature, garbage body: passes the mime sniff, fails to decode.
        $corrupt = UploadedFile::fake()->createWithContent('rusak.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200));
        $component->set('upload', $corrupt)->call('sendMessage')->assertHasErrors('upload');

        config(['system.chat.image_max_pixels' => 1000]);
        $component->set('upload', UploadedFile::fake()->image('besar.png', 100, 100))->call('sendMessage')->assertHasErrors('upload');

        $this->assertSame(0, MessageAttachment::count());
        $this->assertSame(0, Message::count());
    }

    public function test_deleting_a_conversation_removes_its_files(): void
    {
        Storage::fake('local');
        [$me, $mate] = $this->chatPair();
        $conversation = Conversation::between($me->id, $mate->id);
        Storage::disk('local')->put('chat/'.$conversation->id.'/a.pdf', 'x');

        $conversation->delete();

        Storage::disk('local')->assertMissing('chat/'.$conversation->id.'/a.pdf');
    }
}

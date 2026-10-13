<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Inbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    private function notificationFor(User $user, string $title): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'body' => 'isi'],
        ]);
    }

    public function test_inbox_lists_only_own_notifications_and_marks_read(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->notificationFor($me, 'Pesan saya');
        $theirs = $this->notificationFor($other, 'Pesan orang lain');

        $this->actingAs($me);

        Livewire::test(Inbox::class)
            ->set('tab', 'notifications')
            ->assertSee('Pesan saya')
            ->assertDontSee('Pesan orang lain')
            ->call('markAsRead', $theirs->id)
            ->call('markAsRead', $mine->id);

        $this->assertNull($theirs->fresh()->read_at);
        $this->assertNotNull($mine->fresh()->read_at);
    }
}

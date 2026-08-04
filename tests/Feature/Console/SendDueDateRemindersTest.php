<?php

namespace Tests\Feature\Console;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketDueDateReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendDueDateRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_it_notifies_owner_and_responsible_when_a_ticket_is_due_today(): void
    {
        $owner = User::factory()->create();
        $responsible = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'owner_id' => $owner->id,
            'responsible_id' => $responsible->id,
            'due_date' => now(),
        ]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($owner, TicketDueDateReminder::class, function (TicketDueDateReminder $n) use ($owner, $ticket) {
            return $n->toDatabase($owner)['title'] === __('Ticket due today')
                && $n->toMail($owner)->actionUrl === route('filament.resources.tickets.share', $ticket->code);
        });
        Notification::assertSentTo($responsible, TicketDueDateReminder::class);
    }

    public function test_it_notifies_owner_and_responsible_one_day_before_due_date(): void
    {
        $owner = User::factory()->create();
        $responsible = User::factory()->create();
        Ticket::factory()->create([
            'owner_id' => $owner->id,
            'responsible_id' => $responsible->id,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($owner, TicketDueDateReminder::class, function (TicketDueDateReminder $n) use ($owner) {
            return $n->toDatabase($owner)['title'] === __('Ticket due tomorrow');
        });
        Notification::assertSentTo($responsible, TicketDueDateReminder::class);
    }

    public function test_it_does_not_notify_for_a_ticket_due_in_two_days(): void
    {
        $owner = User::factory()->create();
        Ticket::factory()->create(['owner_id' => $owner->id, 'due_date' => now()->addDays(2)]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($owner, TicketDueDateReminder::class);
    }

    public function test_it_does_not_notify_for_an_overdue_ticket(): void
    {
        $owner = User::factory()->create();
        Ticket::factory()->create(['owner_id' => $owner->id, 'due_date' => now()->subDay()]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($owner, TicketDueDateReminder::class);
    }

    public function test_it_does_not_notify_for_a_ticket_without_a_due_date(): void
    {
        $owner = User::factory()->create();
        Ticket::factory()->create(['owner_id' => $owner->id, 'due_date' => null]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($owner, TicketDueDateReminder::class);
    }

    public function test_it_only_notifies_once_when_owner_is_also_responsible(): void
    {
        $owner = User::factory()->create();
        Ticket::factory()->create([
            'owner_id' => $owner->id,
            'responsible_id' => $owner->id,
            'due_date' => now(),
        ]);

        $this->artisan('tickets:due-date-reminders', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentToTimes($owner, TicketDueDateReminder::class, 1);
    }
}

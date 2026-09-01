<?php

namespace Tests\Unit;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\CustomVerifyEmail;
use App\Notifications\TicketCommented;
use App\Notifications\TicketCreated;
use App\Notifications\TicketStatusUpdated;
use App\Notifications\UserCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

/**
 * Builds each notification's mail body. These assert the message is actually
 * renderable - a broken route() or a missing attribute fails here instead of
 * silently killing a queue worker.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_the_ticket_created_mail_builds(): void
    {
        $ticket = Ticket::factory()->create();
        $mail = (new TicketCreated($ticket))->toMail($ticket->owner);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->introLines);
    }

    public function test_the_ticket_created_mail_summarises_the_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $body = implode(' ', (new TicketCreated($ticket))->toMail($ticket->owner)->introLines);

        $this->assertStringContainsString($ticket->name, $body);
        $this->assertStringContainsString($ticket->project->name, $body);
        $this->assertStringContainsString($ticket->owner->name, $body);
        $this->assertStringContainsString($ticket->status->name, $body);
    }

    public function test_the_ticket_created_mail_links_to_the_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $mail = (new TicketCreated($ticket))->toMail($ticket->owner);

        $this->assertStringContainsString($ticket->code, $mail->actionUrl);
    }

    public function test_the_ticket_created_notification_goes_to_mail_and_the_database(): void
    {
        $ticket = Ticket::factory()->create();

        $channels = (new TicketCreated($ticket))->via($ticket->owner);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    /**
     * A ticket whose status has changed, so an activity has been recorded.
     */
    private function ticketWithStatusChange(): Ticket
    {
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create();
        $ticket->update(['status_id' => \App\Models\TicketStatus::factory()->create()->id]);

        return $ticket->fresh();
    }

    public function test_the_ticket_status_updated_mail_builds(): void
    {
        $ticket = $this->ticketWithStatusChange();
        $mail = (new TicketStatusUpdated($ticket))->toMail($ticket->owner);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->introLines);
    }

    public function test_the_ticket_commented_mail_builds(): void
    {
        $comment = TicketComment::factory()->create();
        $mail = (new TicketCommented($comment))->toMail($comment->ticket->owner);

        // This notification sets no explicit subject; Laravel supplies one.
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->introLines);
    }

    public function test_the_ticket_commented_mail_names_the_ticket_and_author(): void
    {
        $comment = TicketComment::factory()->create();
        $mail = (new TicketCommented($comment))->toMail($comment->ticket->owner);

        $body = implode(' ', $mail->introLines);

        // Guards the :ticket / :name placeholders in the translation files.
        $this->assertStringContainsString($comment->ticket->name, $body);
        $this->assertStringContainsString($comment->user->name, $body);
    }

    public function test_the_status_updated_mail_builds_without_an_activity(): void
    {
        // A ticket with no recorded status change hands the notification a null
        // activity; it must still build instead of crashing a queue worker.
        $ticket = Ticket::factory()->create();

        $mail = (new TicketStatusUpdated($ticket))->toMail($ticket->owner);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->introLines);
    }

    public function test_the_status_updated_notification_uses_the_activity_passed_to_it(): void
    {
        $ticket = $this->ticketWithStatusChange();
        $activity = $ticket->activities->last();

        $mail = (new TicketStatusUpdated($ticket, $activity))->toMail($ticket->owner);
        $body = implode(' ', $mail->introLines);

        $this->assertStringContainsString($activity->oldStatus->name, $body);
        $this->assertStringContainsString($activity->newStatus->name, $body);
    }

    public function test_the_status_updated_mail_names_the_ticket(): void
    {
        $ticket = $this->ticketWithStatusChange();
        $mail = (new TicketStatusUpdated($ticket))->toMail($ticket->owner);

        // Guards the :ticket placeholder in the translation files.
        $this->assertStringContainsString(
            $ticket->name,
            $mail->subject.' '.implode(' ', $mail->introLines)
        );
    }

    public function test_the_status_updated_mail_reports_both_statuses(): void
    {
        $ticket = $this->ticketWithStatusChange();
        $activity = $ticket->activities->last();
        $mail = (new TicketStatusUpdated($ticket))->toMail($ticket->owner);

        $body = implode(' ', $mail->introLines);

        // Guards the :oldStatus / :newStatus placeholders.
        $this->assertStringContainsString($activity->oldStatus->name, $body);
        $this->assertStringContainsString($activity->newStatus->name, $body);
    }

    public function test_the_user_created_mail_builds_and_links_the_creation_token(): void
    {
        $user = User::create([
            'name' => 'Newcomer',
            'email' => 'newcomer@example.com',
            'type' => 'db',
        ]);

        $mail = (new UserCreatedNotification($user))->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString($user->creation_token, $mail->actionUrl);
    }

    public function test_the_user_created_mail_names_the_application(): void
    {
        $user = User::create([
            'name' => 'Newcomer',
            'email' => 'newcomer2@example.com',
            'type' => 'db',
        ]);

        $mail = (new UserCreatedNotification($user))->toMail($user);

        $this->assertStringContainsString(config('app.name'), implode(' ', $mail->introLines));
    }

    public function test_the_verify_email_mail_builds_a_signed_url(): void
    {
        $user = User::factory()->create();

        $mail = (new CustomVerifyEmail)->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('signature=', $mail->actionUrl);
    }

    public function test_the_verify_email_mail_greets_the_user_by_name(): void
    {
        $user = User::factory()->create(['name' => 'Fajar']);

        $mail = (new CustomVerifyEmail)->toMail($user);

        $this->assertStringContainsString('Fajar', $mail->greeting);
    }

    public function test_the_verify_email_mail_is_translated_to_the_active_locale(): void
    {
        $user = User::factory()->create();

        app()->setLocale('id');
        $indonesian = (new CustomVerifyEmail)->toMail($user);

        app()->setLocale('en');
        $english = (new CustomVerifyEmail)->toMail($user);

        // Guards against re-introducing hardcoded Indonesian strings: the
        // subject must actually follow the active locale instead of always
        // rendering the same text regardless of app locale.
        $this->assertSame('Verifikasi Alamat Email Anda', $indonesian->subject);
        $this->assertSame('Verify Your Email Address', $english->subject);
    }

    public function test_the_verify_email_notification_is_queued(): void
    {
        // Registration used to build and hand this mail to the mailer inside
        // the request, so the response waited on the SMTP round-trip.
        $this->assertInstanceOf(ShouldQueue::class, new CustomVerifyEmail);
        $this->assertTrue((new CustomVerifyEmail)->afterCommit);
    }

    public function test_the_verification_mail_is_dispatched_as_a_queued_notification(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo(
            $user,
            CustomVerifyEmail::class,
            fn ($notification) => $notification instanceof ShouldQueue
        );
    }

    public function test_every_notification_is_queued(): void
    {
        // Keeps a newly added notification from silently blocking a request.
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');

            $this->assertTrue(
                (new ReflectionClass($class))->implementsInterface(ShouldQueue::class),
                $class.' must implement ShouldQueue.'
            );
        }
    }

    public function test_the_user_created_notification_exposes_an_array_representation(): void
    {
        $user = User::create([
            'name' => 'Newcomer',
            'email' => 'newcomer3@example.com',
            'type' => 'db',
        ]);

        $this->assertIsArray((new UserCreatedNotification($user))->toArray($user));
    }
}

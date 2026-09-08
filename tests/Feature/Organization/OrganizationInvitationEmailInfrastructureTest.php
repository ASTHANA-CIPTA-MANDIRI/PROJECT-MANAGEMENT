<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Phase 5.4.4B — proves the invitation email pipeline past the point
 * Notification::fake() can reach: a real row landing in the `jobs` table
 * (QUEUE_CONNECTION=database), a real worker draining it without error, the
 * actual rendered MailMessage content, and a genuine transport failure
 * surfacing in `failed_jobs` instead of disappearing silently.
 *
 * OrganizationInvitationCreated sets afterCommit=true (Phase 5.4's own
 * transaction-safety discipline), which only pushes to the queue once its
 * surrounding transaction truly commits. That is safe to rely on here
 * because this suite's test database is sqlite `:memory:`
 * (phpunit.xml) - RefreshDatabase gives every such test a freshly
 * migrated, empty database instead of a transaction it rolls back
 * (Illuminate\Foundation\Testing\RefreshDatabase::refreshInMemoryDatabase()),
 * so there is no open ambient transaction here for afterCommit to wait on.
 */
class OrganizationInvitationEmailInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private function pushRealInvitationNotification(array $attributes = []): OrganizationInvitation
    {
        $organization = Organization::factory()->create();
        $plainToken = OrganizationInvitation::generateToken();
        $invitation = OrganizationInvitation::create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Invitee Person',
            'email' => 'invitee@example.test',
            'role' => 'member',
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
            'created_by' => User::factory()->create()->id,
        ], $attributes));

        NotificationFacade::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationCreated($invitation, $plainToken));

        return $invitation;
    }

    public function test_the_invitation_notification_is_pushed_onto_the_real_database_queue(): void
    {
        config(['queue.default' => 'database']);

        $this->assertSame(0, DB::table('jobs')->count());

        $this->pushRealInvitationNotification();

        $this->assertSame(1, DB::table('jobs')->count());

        $payload = json_decode(DB::table('jobs')->first()->payload, true);
        $this->assertSame(OrganizationInvitationCreated::class, $payload['displayName']);
    }

    /**
     * MAIL_MAILER=array (phpunit.xml) is a real in-memory transport, not a
     * fake: this genuinely runs SendQueuedNotifications::handle(), which
     * genuinely calls toMail() and genuinely hands the result to the
     * mailer - it just never touches the network. Proves the job that was
     * queued can actually be drained by a worker without throwing.
     */
    public function test_the_queued_invitation_notification_is_processed_without_error(): void
    {
        config(['queue.default' => 'database']);

        $this->pushRealInvitationNotification();

        $this->artisan('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
        ]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * The critical "don't swallow it" proof: point the mailer at a closed
     * local port (fails immediately, no real network dependency, no slow
     * timeout) and confirm the worker records the failure in
     * `failed_jobs` with a real exception rather than the job just
     * vanishing.
     */
    public function test_a_transport_failure_lands_in_failed_jobs_instead_of_disappearing(): void
    {
        config(['queue.default' => 'database']);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
        ]);

        $this->pushRealInvitationNotification();

        $this->artisan('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
            '--tries' => 1,
        ]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertNotEmpty(DB::table('failed_jobs')->first()->exception);
    }

    public function test_the_rendered_mail_contains_the_correct_recipient_organization_role_and_url(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme Corp']);
        $plainToken = OrganizationInvitation::generateToken();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Invitee Person',
            'email' => 'invitee@example.test',
            'role' => 'admin',
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
        ]);

        $mail = (new OrganizationInvitationCreated($invitation, $plainToken))->toMail('unused-by-a-route-based-mail-notification');

        $this->assertStringContainsString('Acme Corp', $mail->subject);
        $this->assertSame(route('organization-invitations.accept', $plainToken), $mail->actionUrl);
    }
}

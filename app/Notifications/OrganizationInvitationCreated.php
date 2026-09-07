<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 5.4. Sent to an email address, not a User — the recipient may not
 * have an account yet (see App\Http\Livewire\AcceptOrganizationInvitation's
 * "register first" path). Mailed via Notification::route('mail', $email)
 * rather than $user->notify(), the same reason toMail() below never
 * receives a User $notifiable to rely on.
 */
class OrganizationInvitationCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The plain token, held only in memory for exactly as long as it takes
     * to queue this mail — never persisted (see OrganizationInvitation's
     * own docblock). Reconstructing the accept URL from a stored value
     * would mean the plain token existed in the database, defeating the
     * point of hashing it.
     */
    public function __construct(
        private readonly OrganizationInvitation $invitation,
        private readonly string $plainToken,
    ) {
        // Defer queued dispatch until the surrounding DB transaction
        // commits, so a worker never mails a link for an invitation row
        // that rolled back (same discipline as every other ShouldQueue
        // notification in this app — see UserCreatedNotification).
        $this->afterCommit = true;
    }

    /**
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $organizationName = $this->invitation->organization->name;
        $roleLabel = config('system.organizations.affectations.roles.list')[$this->invitation->role] ?? $this->invitation->role;

        return (new MailMessage)
            ->subject(__('You have been invited to join :organization', ['organization' => $organizationName]))
            ->greeting($this->invitation->name !== null
                ? __('Hello :name!', ['name' => $this->invitation->name])
                : __('Hello!'))
            ->line(__('You have been invited to join :organization as :role.', [
                'organization' => $organizationName,
                'role' => $roleLabel,
            ]))
            ->action(__('View invitation'), route('organization-invitations.accept', $this->plainToken))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at->toDayDateTimeString(),
            ]))
            ->line(__('If you were not expecting this invitation, you can safely ignore this email.'));
    }
}

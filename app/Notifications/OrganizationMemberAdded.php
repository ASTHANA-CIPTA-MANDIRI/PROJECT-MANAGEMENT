<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 5.4.1. Sent to an existing User attached directly to an
 * Organization through the unified "Tambah Anggota" flow — the counterpart
 * to OrganizationInvitationCreated for the branch of that flow where the
 * recipient already has an account and needs no invitation/registration
 * step at all.
 */
class OrganizationMemberAdded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly string $role,
    ) {
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
        $roleLabel = config('system.organizations.affectations.roles.list')[$this->role] ?? $this->role;

        return (new MailMessage)
            ->subject(__('You have been added to :organization', ['organization' => $this->organization->name]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('You have been added to :organization as :role.', [
                'organization' => $this->organization->name,
                'role' => $roleLabel,
            ]))
            ->action(__('Open organization'), route('filament.pages.organization'));
    }
}

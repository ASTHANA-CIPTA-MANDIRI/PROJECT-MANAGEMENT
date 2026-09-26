<?php

namespace App\Notifications;

use App\Models\OrganizationSupportAccessGrant;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every Owner of the target Organization the moment a Super Admin
 * requests a Full Access support session against it
 * (App\Support\SupportSessionContext::requestFullAccess()). The Owner is
 * the only actor who can approve or reject that request
 * (App\Filament\Pages\OrganizationSettings::approveFullAccessGrant()/
 * revokeFullAccessGrant()), so this is the only signal they get short of
 * opening the panel and checking manually.
 *
 * Same shape as App\Notifications\TicketCreated: queued, mail+database,
 * afterCommit so the grant row this reads from is guaranteed already
 * committed by the time the queued job actually runs.
 *
 * Both links below carry the target Organization's id as an explicit
 * ?organization= query parameter, not a bare route() call: an Owner can
 * belong to more than one Organization, and OrganizationSettings otherwise
 * always renders whichever one is currently active in
 * App\Support\OrganizationContext (session-based), which is not necessarily
 * this grant's Organization. OrganizationSettings::mount() re-verifies real
 * membership before switching context on that id (same discipline as
 * RoadMap's ?p= project id) - the value is never trusted just because it
 * came from a notification link.
 */
class FullAccessRequested extends Notification implements ShouldQueue
{
    use Queueable;

    private OrganizationSupportAccessGrant $grant;

    public function __construct(OrganizationSupportAccessGrant $grant)
    {
        // Defer queued dispatch until the surrounding DB transaction commits.
        $this->afterCommit = true;
        $this->grant = $grant;
    }

    /**
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Full Access request for :organization', ['organization' => $this->grant->organization->name]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('A Super Admin has requested a Full Access support session for your organization.'))
            ->line(__('Requested by :name', ['name' => $this->grant->requester->name]))
            ->line(__('Reason').': '.$this->grant->reason)
            ->line(__('Expires').': '.$this->grant->request_expires_at->format('d M Y H:i'))
            ->action(__('Review request'), route('filament.pages.organization', ['organization' => $this->grant->organization_id]));
    }

    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Full Access request received'))
            ->icon('heroicon-o-shield-exclamation')
            ->body(__('Requested by :name', ['name' => $this->grant->requester->name]).': '.$this->grant->reason)
            ->actions([
                Action::make('view')
                    ->link()
                    ->icon('heroicon-s-eye')
                    ->url(route('filament.pages.organization', ['organization' => $this->grant->organization_id])),
            ])
            ->getDatabaseMessage();
    }
}

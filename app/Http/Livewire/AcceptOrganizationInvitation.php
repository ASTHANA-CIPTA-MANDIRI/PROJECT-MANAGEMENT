<?php

namespace App\Http\Livewire;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\OrganizationContext;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Phase 5.4 — the single, public landing page for an invitation link
 * (route: organization-invitations.accept). Deliberately outside Filament's
 * panel/Resource system (no auth middleware group), since a guest with no
 * account at all must be able to open it and be told what to do next —
 * exactly the same reasoning behind the pre-existing, equally public
 * App\Http\Livewire\ValidateAccount.
 *
 * The token in the URL is the *only* thing this page trusts from the
 * client. Everything else — which Organization, which role, whether it is
 * still valid, whether the current visitor is even the right person — is
 * re-derived server-side from the invitation row the token resolves to,
 * every time, never from a route/query parameter.
 */
class AcceptOrganizationInvitation extends Component
{
    public string $token;

    public ?OrganizationInvitation $invitation = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->invitation = OrganizationInvitation::findByToken($token);
    }

    /**
     * One of: not_found, revoked, expired, accepted, wrong_identity,
     * unverified, ready. The view renders one message/action per state —
     * never more than one of these is true at a time by construction
     * (isPending() already excludes accepted/revoked/expired from each
     * other).
     */
    public function getStatusProperty(): string
    {
        if ($this->invitation === null) {
            return 'not_found';
        }

        if ($this->invitation->isRevoked()) {
            return 'revoked';
        }

        if ($this->invitation->isAccepted()) {
            return 'accepted';
        }

        if ($this->invitation->isExpired()) {
            return 'expired';
        }

        if (auth()->check() && ! $this->authenticatedEmailMatches()) {
            return 'wrong_identity';
        }

        // Phase 5.4.1: registration -> email verification -> accept, in
        // that order, for a brand-new recipient too — this page is the one
        // gap outside Filament's own panel middleware (which already
        // requires 'verified' for everything else, config/filament.php)
        // where an authenticated-but-unverified user could otherwise reach
        // a mutating action.
        if (auth()->check() && ! auth()->user()->hasVerifiedEmail()) {
            return 'unverified';
        }

        return 'ready';
    }

    /**
     * Whether an account already exists for the invited email — the view
     * uses this to point a guest at either "log in" or "register", never
     * revealing anything beyond what the invitation's own recipient
     * already knows about their own email address.
     */
    public function getRecipientHasAccountProperty(): bool
    {
        return $this->invitation !== null
            && User::where('email', $this->invitation->email)->exists();
    }

    private function authenticatedEmailMatches(): bool
    {
        return $this->invitation !== null
            && auth()->check()
            && Str::lower(auth()->user()->email) === Str::lower($this->invitation->email);
    }

    /**
     * Atomic accept: validate, attach membership, mark accepted — or none
     * of the above. A pessimistic row lock (not an optimistic
     * compare-and-swap) makes a second, concurrent call to this same
     * method simply wait for the first to finish and then see its result,
     * rather than racing it — the simplest way to guarantee only one
     * accept ever turns into a membership.
     */
    public function accept(): void
    {
        if ($this->invitation === null) {
            return;
        }

        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        // Identity binding: the authenticated visitor must be the exact
        // person this invitation was addressed to. A token alone is never
        // enough — Bob holding Alice's link/token must never be able to
        // accept Alice's invitation just by being logged in as Bob.
        if (! $this->authenticatedEmailMatches()) {
            abort(403);
        }

        // Server-side re-check, not just a hidden button: registration must
        // be followed by real email verification before an invitation can
        // turn into membership, exactly like every other mutating action in
        // this app already requires (config/filament.php's 'verified'
        // middleware) — this route is simply the one place outside that
        // panel middleware group where the check has to be explicit.
        if (! $user->hasVerifiedEmail()) {
            abort(403);
        }

        $organizationName = null;
        $joined = false;
        $alreadyMember = false;

        DB::transaction(function () use ($user, &$organizationName, &$joined, &$alreadyMember): void {
            /** @var OrganizationInvitation|null $invitation */
            $invitation = OrganizationInvitation::whereKey($this->invitation->id)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                return;
            }

            $organization = $invitation->organization;

            // The Organization itself is gone (cascadeOnDelete would also
            // have deleted this invitation row, but this stays a safe,
            // explicit guard rather than assuming the FK always ran).
            if ($organization === null) {
                return;
            }

            $organizationName = $organization->name;

            if ($organization->isAccessibleBy($user)) {
                // Already a member (e.g. added separately via "Add existing
                // user" in the meantime, or this is a second concurrent
                // accept that lost the race after the first already
                // attached them) — consume the invitation without a
                // duplicate organization_users row.
                $alreadyMember = true;

                if ($invitation->isPending()) {
                    $invitation->forceFill(['accepted_at' => now()])->save();
                }

                return;
            }

            if (! $invitation->isPending()) {
                // Revoked/expired/already accepted by the time the lock was
                // acquired — nothing to do, the state read above already
                // reflects why.
                return;
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
            $organization->users()->attach($user->id, ['role' => $invitation->role]);
            $joined = true;
        });

        $this->invitation = $this->invitation->fresh();

        if (! $joined && ! $alreadyMember) {
            Notification::make()
                ->title(__('This invitation is no longer valid.'))
                ->danger()
                ->send();

            return;
        }

        if ($organizationName !== null) {
            $organization = $this->invitation->organization;

            // Only switch the active Organization context after membership
            // is confirmed to exist — never before, and never for an
            // unrelated organization the user did not just join.
            if ($organization !== null) {
                OrganizationContext::switch($user, $organization->id);
            }
        }

        Notification::make()
            ->title($joined
                ? __('You have joined :organization.', ['organization' => $organizationName])
                : __('You are already a member of :organization.', ['organization' => $organizationName]))
            ->success()
            ->send();

        $this->redirect(route('filament.pages.organization'));
    }

    public function render(): View
    {
        return view('livewire.accept-organization-invitation');
    }
}

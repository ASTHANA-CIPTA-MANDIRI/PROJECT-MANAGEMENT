<x-filament::page>

    @php($summary = $this->summary())

    <div class="rk-og">
        {{-- Summary card --}}
        <section class="rk-og__hero">
            <span class="rk-og__logo">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($summary['name'], 0, 1)) }}</span>
            <div class="rk-og__hero-main">
                <h2>{{ $summary['name'] }} @if ($summary['role'])<span class="rk-pf__badge">{{ __(ucfirst($summary['role'])) }}</span>@endif</h2>
                <p>{{ trans_choice(':count member|:count members', $summary['members'], ['count' => $summary['members']]) }}</p>
            </div>
            <div class="rk-pf__stats rk-og__stats">
                <div class="rk-pf__stat rk-pf__stat--blue"><span><x-heroicon-o-users class="h-6 w-6" /></span><div><b>{{ $summary['members'] }}</b><small>{{ __('Members') }}</small></div></div>
                @if ($summary['pending'] !== null)
                    <div class="rk-pf__stat rk-pf__stat--green"><span><x-heroicon-o-mail class="h-6 w-6" /></span><div><b>{{ $summary['pending'] }}</b><small>{{ __('Pending invitations') }}</small></div></div>
                @endif
                <div class="rk-pf__stat rk-pf__stat--{{ $summary['trial']['tone'] }}"><span><x-heroicon-o-clock class="h-6 w-6" /></span><div><b>{{ $summary['trial']['label'] }}</b><small>{{ __('Plan status') }}</small></div></div>
            </div>
        </section>

        <form wire:submit.prevent="save">
            {{ $this->form }}

            @if ($this->canManageOrganization())
                <x-filament::button type="submit" class="mt-4">
                    {{ __('Save changes') }}
                </x-filament::button>
            @endif
        </form>

        {{ $this->table }}

        @if ($this->canManageOrganization())
            @php($pendingInvitations = $this->pendingInvitations())

            @if ($pendingInvitations->isNotEmpty())
                <section class="rk-og__panel">
                    <div class="rk-og__panel-head">
                        <h3>{{ __('Pending invitations') }} <span class="rk-pf__count">{{ $pendingInvitations->count() }}</span></h3>
                    </div>

                    @foreach ($pendingInvitations as $invitation)
                        <div class="rk-og__row" wire:key="invitation-{{ $invitation->id }}">
                            <span class="rk-og__avatar rk-pf__tile--blue">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($invitation->email, 0, 1)) }}</span>
                            <div class="rk-og__row-main">
                                <b>{{ $invitation->email }}</b>
                                <small>
                                    {{ config('system.organizations.affectations.roles.list')[$invitation->role] ?? $invitation->role }}
                                    &middot;
                                    {{ __('Expires :date', ['date' => $invitation->expires_at->diffForHumans()]) }}
                                </small>
                            </div>

                            <x-filament::button
                                color="danger"
                                size="sm"
                                wire:click="revokeInvitation({{ $invitation->id }})"
                                data-swal-confirm="{{ __('Revoke this invitation? The invitee will no longer be able to join with it.') }}"
                                data-swal-severity="danger"
                            >
                                {{ __('Revoke') }}
                            </x-filament::button>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- Phase 7 (Full Access UI/UX Gate). Owner-only (canApproveFullAccess()
                 checks Organization::isOwnedBy(), not the Owner+Admin
                 isManageableBy() the rest of this page uses) — see that
                 method's own docblock on the Filament page class. --}}
            @if ($this->canApproveFullAccess())
                @php($fullAccessGrants = $this->fullAccessGrants())
                @php($finishedFullAccessGrantStatuses = ['consumed', 'revoked', 'expired'])
                @php($statusTone = ['requested' => 'yellow', 'approved' => 'blue', 'consumed' => 'gray', 'revoked' => 'red', 'expired' => 'gray'])
                {{-- Deliberately NOT $fullAccessGrants->contains(...): that collection is
                     capped at 20 rows (fullAccessGrants()'s own ->limit(20)), so an
                     organization with more than 20 grants where the 20 newest are all
                     still requested/approved would never show this button even though
                     older finished grants exist. hasFinishedFullAccessGrants() runs its
                     own unlimited query instead — see its docblock on the Page class. --}}
                @php($hasFinishedFullAccessGrants = $this->hasFinishedFullAccessGrants())

                @if ($fullAccessGrants->isNotEmpty())
                    <section class="rk-og__panel">
                        <div class="rk-og__panel-head">
                            <h3>{{ __('Full Access requests') }} <span class="rk-pf__count">{{ $fullAccessGrants->count() }}</span></h3>

                            @if ($hasFinishedFullAccessGrants)
                                {{-- Same confirm()-gated Livewire call pattern as the destructive
                                     controls on organization-support-view.blade.php (Delete/Restore
                                     Project) — bulk-deletes every consumed/revoked/expired grant for
                                     this organization in one call, never the still-active
                                     requested/approved ones (see deleteAllFinishedFullAccessGrants()'s
                                     own docblock). --}}
                                @php($deleteAllFinishedConfirm = __('Delete all finished Full Access requests (consumed, revoked, or expired)? Requests still awaiting a decision are never affected.'))
                                <button
                                    type="button"
                                    x-data
                                    x-on:click="
                                        RencanakanAlert.confirm({ severity: 'danger', text: @js($deleteAllFinishedConfirm) }).then((confirmed) => { if (confirmed) {
                                            $wire.deleteAllFinishedFullAccessGrants()
                                        } })
                                    "
                                    class="rk-og__ghost"
                                >
                                    <x-heroicon-o-trash class="h-4 w-4" /> {{ __('Delete all finished') }}
                                </button>
                            @endif
                        </div>

                        @foreach ($fullAccessGrants as $grant)
                            @php($status = $grant->status())

                            <div class="rk-og__row rk-og__row--stack" wire:key="grant-{{ $grant->id }}">
                                <div class="rk-og__row-top">
                                    <span class="rk-og__avatar rk-pf__tile--{{ $statusTone[$status] }}">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($grant->requester?->name ?? '?', 0, 1)) }}</span>
                                    <div class="rk-og__row-main">
                                        <b>{{ $grant->requester?->name }} ({{ $grant->requester?->email }})</b>
                                        <small>{{ $grant->reason }}</small>
                                    </div>
                                    <span class="rk-og__chip rk-og__chip--{{ $statusTone[$status] }}">
                                        @if ($status === 'requested') {{ __('Requested') }}
                                        @elseif ($status === 'approved') {{ __('Approved') }}
                                        @elseif ($status === 'consumed') {{ __('Consumed') }}
                                        @elseif ($status === 'revoked') {{ __('Revoked') }}
                                        @else {{ __('Expired') }}
                                        @endif
                                    </span>
                                </div>

                                <div class="rk-og__meta">
                                    <span>{{ __('Scope') }}: {{ __('Belum ada kapabilitas yang disetujui untuk Full Access.') }}</span>
                                    <span>
                                        {{ __('Requested at') }}: {{ $grant->requested_at?->format('d M Y H:i') }}
                                        &middot; {{ __('Expires') }}: {{ $grant->request_expires_at?->format('d M Y H:i') }}
                                    </span>

                                    @if ($grant->approved_at !== null)
                                        <span>
                                            {{ __('Approved by') }}: {{ $grant->approver?->name ?? '—' }}
                                            {{ __('at') }} {{ $grant->approved_at->format('d M Y H:i') }}
                                            &middot; {{ __('Grant expires') }}: {{ $grant->grant_expires_at?->format('d M Y H:i') }}
                                        </span>
                                    @endif

                                    @if ($grant->revoked_at !== null)
                                        <span>
                                            {{ __('Revoked by') }}: {{ $grant->revoker?->name ?? '—' }}
                                            {{ __('at') }} {{ $grant->revoked_at->format('d M Y H:i') }}
                                        </span>
                                    @endif

                                    @if ($grant->session)
                                        <span>
                                            {{ __('Session status') }}:
                                            @if ($grant->session->ended_at !== null)
                                                {{ __('Ended') }}
                                            @elseif ($grant->session->isActive())
                                                {{ __('Active') }}
                                            @else
                                                {{ __('Expired') }}
                                            @endif
                                            &middot; {{ __('Started') }}: {{ $grant->session->started_at?->format('d M Y H:i') }}
                                            @if ($grant->session->ended_at !== null)
                                                &middot; {{ __('Ended at') }}: {{ $grant->session->ended_at->format('d M Y H:i') }}
                                            @else
                                                &middot; {{ __('Expires') }}: {{ $grant->session->expires_at?->format('d M Y H:i') }}
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                @if ($status === 'requested')
                                    <div class="rk-og__actions">
                                        <x-filament::button size="sm" wire:click="approveFullAccessGrant({{ $grant->id }})"
                                            data-swal-confirm="{{ __('Approve this Full Access request?') }}">
                                            {{ __('Approve') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="danger" wire:click="revokeFullAccessGrant({{ $grant->id }})"
                                            data-swal-confirm="{{ __('Reject this Full Access request?') }}" data-swal-severity="danger">
                                            {{ __('Reject') }}
                                        </x-filament::button>
                                    </div>
                                @elseif ($status === 'approved')
                                    <div class="rk-og__actions">
                                        <x-filament::button size="sm" color="danger" wire:click="revokeFullAccessGrant({{ $grant->id }})"
                                            data-swal-confirm="{{ __('Revoke this Full Access grant?') }}" data-swal-severity="danger">
                                            {{ __('Revoke') }}
                                        </x-filament::button>
                                    </div>
                                @elseif (in_array($status, $finishedFullAccessGrantStatuses, true))
                                    {{-- Only ever shown for consumed/revoked/expired rows —
                                         deleteFullAccessGrant() itself silently refuses
                                         requested/approved grants too, this just keeps the
                                         button from appearing on a row it would no-op on. --}}
                                    <div class="rk-og__actions">
                                        @php($deleteGrantConfirm = __('Delete this Full Access request? This cannot be undone.'))
                                        <button
                                            type="button"
                                            x-data
                                            x-on:click="
                                                RencanakanAlert.confirm({ severity: 'danger', text: @js($deleteGrantConfirm) }).then((confirmed) => { if (confirmed) {
                                                    $wire.deleteFullAccessGrant({{ $grant->id }})
                                                } })
                                            "
                                            class="rk-og__ghost"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" /> {{ __('Delete') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </section>
                @endif
            @endif
        @endif
    </div>

</x-filament::page>

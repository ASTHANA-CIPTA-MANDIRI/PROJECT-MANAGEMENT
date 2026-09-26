<x-filament::page>

    <div class="w-full flex flex-col gap-8">
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
                <div class="flex flex-col gap-2">
                    <h3 class="text-base font-medium">{{ __('Pending invitations') }}</h3>

                    <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg">
                        @foreach ($pendingInvitations as $invitation)
                            <div class="flex items-center justify-between gap-4 px-4 py-3">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">{{ $invitation->email }}</span>
                                    <span class="text-xs text-gray-500">
                                        {{ config('system.organizations.affectations.roles.list')[$invitation->role] ?? $invitation->role }}
                                        &middot;
                                        {{ __('Expires :date', ['date' => $invitation->expires_at->diffForHumans()]) }}
                                    </span>
                                </div>

                                <x-filament::button
                                    color="danger"
                                    size="sm"
                                    wire:click="revokeInvitation({{ $invitation->id }})"
                                >
                                    {{ __('Revoke') }}
                                </x-filament::button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Phase 7 (Full Access UI/UX Gate). Owner-only (canApproveFullAccess()
                 checks Organization::isOwnedBy(), not the Owner+Admin
                 isManageableBy() the rest of this page uses) — see that
                 method's own docblock on the Filament page class. --}}
            @if ($this->canApproveFullAccess())
                @php($fullAccessGrants = $this->fullAccessGrants())
                @php($finishedFullAccessGrantStatuses = ['consumed', 'revoked', 'expired'])
                @php($hasFinishedFullAccessGrants = $fullAccessGrants->contains(fn ($grant) => in_array($grant->status(), $finishedFullAccessGrantStatuses, true)))

                @if ($fullAccessGrants->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between gap-4">
                            <h3 class="text-base font-medium">{{ __('Full Access requests') }}</h3>

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
                                        if (confirm(@js($deleteAllFinishedConfirm))) {
                                            $wire.deleteAllFinishedFullAccessGrants()
                                        }
                                    "
                                    class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                >
                                    {{ __('Delete all finished') }}
                                </button>
                            @endif
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg">
                            @foreach ($fullAccessGrants as $grant)
                                @php($status = $grant->status())

                                <div class="flex flex-col gap-2 px-4 py-3">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium">{{ $grant->requester?->name }} ({{ $grant->requester?->email }})</span>
                                            <span class="text-xs text-gray-500">{{ $grant->reason }}</span>
                                        </div>
                                        <span class="text-xs font-semibold uppercase">
                                            @if ($status === 'requested') {{ __('Requested') }}
                                            @elseif ($status === 'approved') {{ __('Approved') }}
                                            @elseif ($status === 'consumed') {{ __('Consumed') }}
                                            @elseif ($status === 'revoked') {{ __('Revoked') }}
                                            @else {{ __('Expired') }}
                                            @endif
                                        </span>
                                    </div>

                                    <div class="text-xs text-gray-500 flex flex-col gap-1">
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
                                        <div class="flex gap-2">
                                            <x-filament::button size="sm" wire:click="approveFullAccessGrant({{ $grant->id }})">
                                                {{ __('Approve') }}
                                            </x-filament::button>
                                            <x-filament::button size="sm" color="danger" wire:click="revokeFullAccessGrant({{ $grant->id }})">
                                                {{ __('Reject') }}
                                            </x-filament::button>
                                        </div>
                                    @elseif ($status === 'approved')
                                        <div class="flex gap-2">
                                            <x-filament::button size="sm" color="danger" wire:click="revokeFullAccessGrant({{ $grant->id }})">
                                                {{ __('Revoke') }}
                                            </x-filament::button>
                                        </div>
                                    @elseif (in_array($status, $finishedFullAccessGrantStatuses, true))
                                        {{-- Only ever shown for consumed/revoked/expired rows —
                                             deleteFullAccessGrant() itself silently refuses
                                             requested/approved grants too, this just keeps the
                                             button from appearing on a row it would no-op on. --}}
                                        <div class="flex gap-2">
                                            @php($deleteGrantConfirm = __('Delete this Full Access request? This cannot be undone.'))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js($deleteGrantConfirm))) {
                                                        $wire.deleteFullAccessGrant({{ $grant->id }})
                                                    }
                                                "
                                                class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>

</x-filament::page>

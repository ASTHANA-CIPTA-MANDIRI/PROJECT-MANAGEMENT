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

                @if ($fullAccessGrants->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        <h3 class="text-base font-medium">{{ __('Full Access requests') }}</h3>

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

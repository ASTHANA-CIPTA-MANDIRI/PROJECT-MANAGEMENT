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
        @endif
    </div>

</x-filament::page>

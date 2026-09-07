<x-filament::layouts.card>

    <div class="mb-6">
        <h2 class="font-bold tracking-tight text-center text-2xl">
            {{ __('Organization invitation') }}
        </h2>
    </div>

    @if ($this->status === 'not_found')
        <p class="text-sm text-center">{{ __('This invitation link is invalid.') }}</p>
    @elseif ($this->status === 'revoked')
        <p class="text-sm text-center">{{ __('This invitation has been revoked.') }}</p>
    @elseif ($this->status === 'expired')
        <p class="text-sm text-center">{{ __('This invitation has expired.') }}</p>
    @elseif ($this->status === 'accepted')
        <p class="text-sm text-center">{{ __('This invitation has already been accepted.') }}</p>
    @elseif ($this->status === 'wrong_identity')
        <p class="text-sm text-center">
            {{ __('This invitation was sent to :email. Please sign out and sign in with that address to accept it.', [
                'email' => $invitation->email,
            ]) }}
        </p>
    @else
        <div class="flex flex-col items-center gap-4 text-center">
            <p class="text-sm">
                {{ __('You have been invited to join :organization as :role.', [
                    'organization' => $invitation->organization->name,
                    'role' => config('system.organizations.affectations.roles.list')[$invitation->role] ?? $invitation->role,
                ]) }}
            </p>

            @auth
                <x-filament::button wire:click="accept" class="w-full">
                    {{ __('Accept invitation') }}
                </x-filament::button>
            @else
                @if ($this->recipientHasAccount)
                    <p class="text-sm text-gray-500">
                        {{ __('Please sign in with :email to accept this invitation.', ['email' => $invitation->email]) }}
                    </p>
                    <x-filament::button tag="a" href="{{ route('login') }}" class="w-full">
                        {{ __('Sign in') }}
                    </x-filament::button>
                @else
                    <p class="text-sm text-gray-500">
                        {{ __('Please create an account with :email, then return to this link to accept.', ['email' => $invitation->email]) }}
                    </p>
                    <x-filament::button tag="a" href="{{ route('register') }}" class="w-full">
                        {{ __('Create an account') }}
                    </x-filament::button>
                @endif
            @endauth
        </div>
    @endif

</x-filament::layouts.card>

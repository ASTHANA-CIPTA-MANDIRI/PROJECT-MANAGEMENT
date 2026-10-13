{{-- No x-filament::layouts.card here: that component wraps the page in a second
     layouts.base, and organization-invitations/accept.blade.php already supplies
     the document, so Livewire/Alpine used to be loaded twice and broke wire:click. --}}
@php
    $state = match ($this->status) {
        'not_found' => ['tone' => 'red', 'icon' => 'x-circle', 'title' => __('Invitation not found'), 'text' => __('This invitation link is invalid.')],
        'revoked' => ['tone' => 'red', 'icon' => 'ban', 'title' => __('Invitation withdrawn'), 'text' => __('This invitation has been revoked.')],
        'expired' => ['tone' => 'yellow', 'icon' => 'clock', 'title' => __('Invitation expired'), 'text' => __('This invitation has expired.')],
        'accepted' => ['tone' => 'green', 'icon' => 'check-circle', 'title' => __('Already accepted'), 'text' => __('This invitation has already been accepted.')],
        'wrong_identity' => ['tone' => 'yellow', 'icon' => 'exclamation', 'title' => __('Different account'), 'text' => __('This invitation was sent to :email. Please sign out and sign in with that address to accept it.', ['email' => $invitation?->email])],
        'unverified' => ['tone' => 'yellow', 'icon' => 'mail', 'title' => __('Verify your email'), 'text' => __('Please verify your email address before accepting this invitation.')],
        default => ['tone' => 'blue', 'icon' => 'office-building', 'title' => __('You are invited'), 'text' => null],
    };
@endphp

<div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-primary-50/40 text-gray-900 filament-breezy-auth-component filament-login-page">
    <div class="absolute -top-16 -right-10 h-56 w-56 rounded-full bg-primary-200/60 sm:-top-24 sm:-right-16 sm:h-96 sm:w-96"></div>
    <div class="absolute -bottom-20 -left-12 h-48 w-48 rounded-full bg-orange-200/50 sm:-bottom-28 sm:-left-20 sm:h-80 sm:w-80"></div>

    <div class="relative mx-4 my-8 w-full max-w-4xl space-y-4">
        <div class="filament-breezy-auth-card relative flex overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl">
            <div class="hidden items-center justify-center rounded-l-2xl border-r border-gray-100 px-12 lg:flex lg:w-1/2">
                <x-filament::brand />
            </div>

            <div class="w-full rounded-r-2xl p-6 sm:p-10 lg:w-1/2">
                <div class="rk-inv">
                    <div class="flex w-full justify-center lg:hidden"><x-filament::brand /></div>

                    <span class="rk-inv__icon rk-inv__icon--{{ $state['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$state['icon']" class="h-8 w-8" /></span>
                    <h2>{{ $state['title'] }}</h2>

                    @if ($this->status === 'unverified')
                        <p>{{ $state['text'] }}</p>
                        <x-filament::button tag="a" href="{{ route('verification.notice') }}" class="w-full">
                            {{ __('Verify email') }}
                        </x-filament::button>
                    @elseif ($this->status === 'ready')
                        <p>
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
                                <p class="rk-inv__hint">
                                    {{ __('Please sign in with :email to accept this invitation.', ['email' => $invitation->email]) }}
                                </p>
                                <x-filament::button tag="a" href="{{ route('login') }}" class="w-full">
                                    {{ __('Sign in') }}
                                </x-filament::button>
                            @else
                                <p class="rk-inv__hint">
                                    {{ __('Please create an account with :email, then return to this link to accept.', ['email' => $invitation->email]) }}
                                </p>
                                <x-filament::button tag="a" href="{{ route('register') }}" class="w-full">
                                    {{ __('Create an account') }}
                                </x-filament::button>
                            @endif
                        @endauth
                    @else
                        <p>{{ $state['text'] }}</p>
                    @endif

                    <span class="rk-inv__foot">Rencanakan by Ethes Digital</span>
                </div>
            </div>
        </div>
    </div>
</div>

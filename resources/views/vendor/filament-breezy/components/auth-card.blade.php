@props(['action'])
<div @class([
    'relative flex items-center justify-center min-h-screen overflow-hidden bg-primary-50/40 text-gray-900 filament-breezy-auth-component filament-login-page',
    'dark:bg-gray-900 dark:text-white' => config('filament.dark_mode'),
])>

    {{-- Decorative blobs, behind the card (scaled down on small screens) --}}
    <div class="absolute -top-16 -right-10 w-56 h-56 sm:-top-24 sm:-right-16 sm:w-96 sm:h-96 rounded-full bg-primary-200/60 dark:bg-primary-500/10"></div>
    <div class="absolute -bottom-20 -left-12 w-48 h-48 sm:-bottom-28 sm:-left-20 sm:w-80 sm:h-80 rounded-full bg-orange-200/50 dark:bg-orange-500/10"></div>

    <div class="relative w-full max-w-5xl mx-4 my-8 space-y-4">
        <div @class([
            'flex overflow-hidden bg-white border border-gray-200 shadow-xl rounded-2xl relative filament-breezy-auth-card',
            'dark:bg-gray-900/50 dark:border-gray-700' => config('filament.dark_mode'),
        ])>
            {{-- Illustration side (hidden on small screens) --}}
            <div class="hidden lg:flex lg:w-1/2 items-center justify-center rounded-l-2xl border-r border-gray-100 dark:border-gray-700 px-12">
                <x-filament::brand />
            </div>

            {{-- Form side --}}
            <div class="w-full lg:w-1/2 rounded-r-2xl p-6 sm:p-8">
                <form wire:submit.prevent="{{ $action }}" class="space-y-6 rounded-2xl">
                    {{ $slot }}
                </form>
            </div>
        </div>

        {{ $this->modal }}
        <x-filament::footer />
    </div>

    @livewire('notifications')

</div>

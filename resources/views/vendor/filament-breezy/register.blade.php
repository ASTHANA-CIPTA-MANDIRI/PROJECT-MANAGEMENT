<x-filament-breezy::auth-card
    action="register"
    x-data="{ loading: false }"
    @submit.prevent="loading = true; $nextTick(() => $el.closest('form').submit())">

    <!-- Loading overlay -->
    <div
        x-show="loading"
        x-transition:enter="transition-opacity ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-init="$el.style.display = 'none'"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white p-6 rounded-xl shadow-xl flex items-center space-x-4">
            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8z" />
            </svg>
            <span class="text-gray-700 font-medium">Data sedang diproses...</span>
        </div>
    </div>

    <div class="w-full flex justify-center lg:hidden">
        <x-filament::brand />
    </div>

    <div>
        <h2 class="font-bold tracking-tight text-center text-2xl">
            {{ __('filament-breezy::default.registration.heading') }}
        </h2>
        <p class="mt-2 text-sm text-center">
            {{ __('filament-breezy::default.or') }}
            <a class="text-primary-600" href="{{ route('filament.auth.login') }}">
                {{ strtolower(__('filament::login.heading')) }}
            </a>
        </p>
    </div>

    @if(config('filament-socialite.enabled'))
    <x-filament-socialite::buttons />
    @endif

    {{ $this->form }}

    <x-filament::button
        type="submit"
        class="w-full flex items-center justify-center gap-2"
        x-bind:disabled="loading">
        <svg
            x-show="loading"
            class="animate-spin h-5 w-5 text-white"
            xmlns="http://www.w3.org/2000/svg"
            fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8z" />
        </svg>
        <span>
            {{ __('filament-breezy::default.registration.submit.label') }}
        </span>
    </x-filament::button>

    <div class="text-center mt-4">
        <span class="text-sm text-gray-500">Rencanakan by Ethes Digital</span>
    </div>
</x-filament-breezy::auth-card>
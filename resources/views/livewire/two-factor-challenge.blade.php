<x-filament::layouts.card>

    <form wire:submit.prevent="authenticate">

        <div class="mb-10">
            <h2 class="font-bold tracking-tight text-center text-2xl">
                {{ __('Two-factor authentication') }}
            </h2>
            <p class="mt-2 text-sm text-center">
                {{ $usingRecoveryCode
                    ? __('Enter one of your recovery codes to continue.')
                    : __('Enter the code from your authenticator app to continue.') }}
            </p>
        </div>

        {{ $this->form }}

        <x-filament::button type="submit" class="w-full mt-5">
            {{ __('Verify') }}
        </x-filament::button>

        <div class="text-center mt-4">
            <button type="button" wire:click="toggleRecoveryCode"
                    class="text-sm text-gray-500 hover:text-primary-500 hover:underline">
                {{ $usingRecoveryCode
                    ? __('Use an authenticator code instead')
                    : __('Use a recovery code instead') }}
            </button>
        </div>

    </form>

</x-filament::layouts.card>

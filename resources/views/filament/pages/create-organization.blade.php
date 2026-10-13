<x-filament::page>

    <div class="w-full flex flex-col gap-6">
        <p class="rk-lead">{{ __('Give your organization a name. You can invite your team as soon as it is created.') }}</p>

        <form wire:submit.prevent="create">
            {{ $this->form }}

            <x-filament::button type="submit" class="mt-4">
                {{ __('Create organization') }}
            </x-filament::button>
        </form>
    </div>

</x-filament::page>

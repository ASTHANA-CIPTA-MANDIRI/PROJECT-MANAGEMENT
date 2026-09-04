<x-filament::page>

    <div class="w-full flex flex-col gap-8">
        <form wire:submit.prevent="create">
            {{ $this->form }}

            <x-filament::button type="submit" class="mt-4">
                {{ __('Create organization') }}
            </x-filament::button>
        </form>
    </div>

</x-filament::page>

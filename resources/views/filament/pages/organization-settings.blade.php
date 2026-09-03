<x-filament::page>

    <div class="w-full flex flex-col gap-8">
        <form wire:submit.prevent="save">
            {{ $this->form }}

            <x-filament::button type="submit" class="mt-4">
                {{ __('Save') }}
            </x-filament::button>
        </form>

        {{ $this->table }}
    </div>

</x-filament::page>

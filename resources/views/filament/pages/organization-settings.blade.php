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
    </div>

</x-filament::page>

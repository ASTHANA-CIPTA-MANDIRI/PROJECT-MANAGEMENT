<x-filament::page>
    <div class="rk-ts">
        <p class="rk-lead">{{ __('Download your logged hours for a period as a CSV file you can open in Excel or Google Sheets.') }}</p>

        <div class="rk-ts__grid">
            <section class="rk-og__panel">
                <div class="rk-og__panel-head"><h3>{{ __('Choose a period') }}</h3></div>

                <div class="rk-ts__chips">
                    <button type="button" wire:click="setRange('week')">{{ __('This week') }}</button>
                    <button type="button" wire:click="setRange('month')">{{ __('This month') }}</button>
                    <button type="button" wire:click="setRange('last_month')">{{ __('Last month') }}</button>
                    <button type="button" wire:click="setRange('year')">{{ __('This year') }}</button>
                </div>

                <form wire:submit.prevent="create">
                    {{ $this->form }}

                    <x-filament::button type="submit" class="mt-4">
                        <span class="inline-flex items-center gap-2"><x-heroicon-o-download class="h-5 w-5" /> {{ __('Create report') }}</span>
                    </x-filament::button>
                </form>
            </section>

            <section class="rk-og__panel">
                <div class="rk-og__panel-head"><h3>{{ __('What is in the file') }}</h3></div>
                <p class="rk-ts__note">{{ __('One row per time entry you logged in the period. Only your own hours are included.') }}</p>
                <div class="rk-ts__cols">
                    @foreach ($this->exportColumns() as $column)
                        <span>{{ $column }}</span>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-filament::page>

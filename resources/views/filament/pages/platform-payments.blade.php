<x-filament::page>
    @php($stats = $this->paymentStats())

    <div class="rk-pl rk-pp">
        <div class="rk-pl__stats">
            @foreach ($stats as $stat)
                <div class="rk-pl__stat">
                    <span class="rk-pl__stat-icon rk-pf__tile--{{ $stat['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-6 w-6" /></span>
                    <div>
                        <small>{{ $stat['label'] }}</small>
                        <b>{{ $stat['value'] }}</b>
                        <em class="is-flat">{{ $stat['note'] }}</em>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rk-pl__table">
            {{ $this->table }}

            <div class="rk-pp__legend" aria-label="{{ __('Actions') }}">
                <span style="color:#3b82d6"><x-heroicon-o-eye aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('Check status') }}</em></span>
                <span style="color:#4fb87a"><x-heroicon-o-refresh aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('Sync') }} ({{ __('pending only') }})</em></span>
                <span class="rk-pp__legend-recover"><x-heroicon-o-reply aria-hidden="true" /> <em>{{ __('Recover payment (expired only)') }}</em></span>
                <span style="color:#ee8f6a"><x-heroicon-o-clock aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('History') }}</em></span>
            </div>
        </div>
    </div>
</x-filament::page>

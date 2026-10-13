{{-- Status cards + filter tabs at the top of the Tickets table card. Rendered
     inside the ListTickets Livewire page, so wire:click reaches
     applyStatusCard() / selectTab(). --}}
<div class="rk-stat-cards">
    @foreach ($cards as $card)
        <button
            type="button"
            wire:click="applyStatusCard(@js($card['key']))"
            @class(['rk-stat-cards__card', 'is-active' => $card['active']])
            style="--c: {{ $card['color'] }}"
            aria-pressed="{{ $card['active'] ? 'true' : 'false' }}"
        >
            <span class="rk-stat-cards__icon">
                <x-dynamic-component :component="$card['icon']" class="h-5 w-5" />
            </span>
            <span class="rk-stat-cards__text">
                <span class="rk-stat-cards__label">{{ $card['label'] }}</span>
                <span class="rk-stat-cards__row">
                    <span class="rk-stat-cards__value">{{ $card['count'] }}</span>
                    <span class="rk-stat-cards__percent">{{ $card['percent'] }}%</span>
                </span>
            </span>
        </button>
    @endforeach
</div>

<div class="rk-tabs" role="tablist">
    @foreach ($tabs as $tab)
        <button
            type="button"
            role="tab"
            wire:click="selectTab(@js($tab['key']))"
            @class(['rk-tabs__tab', 'is-active' => $tab['active']])
            aria-selected="{{ $tab['active'] ? 'true' : 'false' }}"
        >
            {{ $tab['label'] }}
            <span @class(['rk-tabs__count', 'is-danger' => $tab['danger'] && $tab['count'] > 0])>({{ $tab['count'] }})</span>
        </button>
    @endforeach
</div>

{{-- Pill multi-select for the support session tables (labels). Tick any number of
     options, search when there are many, then "Apply": one confirmation, then a single
     `$wire.<method>(<record id>, [ids])` call. Closing or cancelling changes nothing.
     The list is position:fixed so the table's scroll box never clips it. --}}
@props(['options', 'current', 'method', 'recordId', 'confirm'])

@php
    $items = collect($options)
        ->map(fn ($option) => ['id' => $option['id'], 'label' => $option['label'], 'color' => $option['color'] ?? null])
        ->values()
        ->all();
@endphp

<div class="rk-ss rk-ss--multi"
     x-data="{
         open: false,
         saved: @js(array_values($current)),
         draft: @js(array_values($current)),
         items: @js($items),
         q: '',
         pos: { top: 0, left: 0 },
         get shown() { return this.items.filter((item) => item.label.toLowerCase().includes(this.q.toLowerCase())) },
         get chosen() { return this.items.filter((item) => this.saved.includes(item.id)) },
         get dirty() { return JSON.stringify([...this.draft].sort()) !== JSON.stringify([...this.saved].sort()) },
         toggle() {
             this.open = ! this.open;
             if (this.open) {
                 this.draft = [...this.saved];
                 this.q = '';
                 const r = this.$refs.trigger.getBoundingClientRect();
                 this.pos = { top: r.bottom + 6, left: Math.min(r.left, window.innerWidth - 280) };
             }
         },
         flip(id) {
             this.draft = this.draft.includes(id) ? this.draft.filter((x) => x !== id) : [...this.draft, id];
         },
         apply() {
             const ids = [...this.draft];
             this.open = false;
             RencanakanAlert.confirm({ severity: 'warning', text: @js($confirm) }).then((confirmed) => {
                 if (confirmed) {
                     this.$wire.{{ $method }}({{ $recordId }}, ids);
                     this.saved = ids;
                 }
             });
         },
     }"
     x-on:keydown.escape="open = false"
     x-on:click.outside="open = false"
     x-on:scroll.window="open = false"
     x-on:resize.window="open = false">
    <button type="button" x-ref="trigger" class="rk-ss__btn rk-ss__btn--multi" x-on:click="toggle()" aria-haspopup="listbox" x-bind:aria-expanded="open">
        <span class="rk-ss__muted" x-show="chosen.length === 0">{{ __('Pick labels') }}</span>
        <span class="rk-ss__dots" x-show="chosen.length > 0">
            <template x-for="item in chosen.slice(0, 4)" :key="item.id"><i class="rk-ss__dot" x-bind:style="item.color ? 'background:' + item.color : ''"></i></template>
        </span>
        <span x-show="chosen.length > 0" x-text="chosen.length + ' ' + @js(__('labels'))"></span>
        <x-heroicon-o-chevron-down class="rk-ss__chevron" x-bind:class="{ 'rotate-180': open }" />
    </button>

    <div class="rk-ss__list rk-ss__list--multi" x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-bind:style="`top: ${pos.top}px; left: ${pos.left}px`">
        <input type="text" class="rk-ss__search" x-model="q" placeholder="{{ __('Search') }}" x-show="items.length > 6">
        <ul role="listbox" aria-multiselectable="true">
            <template x-for="item in shown" :key="item.id">
                <li>
                    <button type="button" class="rk-ss__item" x-bind:class="{ 'is-selected': draft.includes(item.id) }" x-on:click="flip(item.id)">
                        <span class="rk-ss__box"><x-heroicon-s-check x-show="draft.includes(item.id)" /></span>
                        <i class="rk-ss__dot" x-show="item.color" x-bind:style="item.color ? 'background:' + item.color : ''"></i>
                        <span x-text="item.label"></span>
                    </button>
                </li>
            </template>
            <li class="rk-ss__empty" x-show="shown.length === 0">{{ __('No results') }}</li>
        </ul>
        <div class="rk-ss__foot">
            <small x-text="draft.length + ' / ' + items.length"></small>
            <button type="button" class="rk-ss__apply" x-bind:disabled="! dirty" x-on:click="apply()">{{ __('Apply') }}</button>
        </div>
    </div>
</div>

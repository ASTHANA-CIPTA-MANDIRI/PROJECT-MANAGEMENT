{{-- Pill dropdown for the support session tables. It replaces a native <select>
     (whose open list the OS draws) while keeping the same contract: picking a value
     asks for confirmation first, and only a confirmed pick calls the Livewire method
     (`$wire.<method>(<record id>, <value>)`); a cancelled pick changes nothing.
     The list is position:fixed so the table's scroll box never clips it. --}}
@props(['options', 'current', 'method', 'recordId', 'confirm'])

@php
    $items = collect($options)
        ->map(fn ($option) => ['id' => $option['id'], 'label' => $option['label'], 'color' => $option['color'] ?? null])
        ->values()
        ->all();
@endphp

<div class="rk-ss"
     x-data="{
         open: false,
         value: @js($current),
         items: @js($items),
         pos: { top: 0, left: 0, minWidth: 0 },
         get picked() { return this.items.find((item) => item.id === this.value) || null },
         toggle() {
             this.open = ! this.open;
             if (this.open) {
                 const r = this.$refs.trigger.getBoundingClientRect();
                 this.pos = { top: r.bottom + 6, left: r.left, minWidth: r.width };
             }
         },
         choose(item) {
             this.open = false;
             if (item.id === this.value) { return; }
             RencanakanAlert.confirm({ severity: 'warning', text: @js($confirm) }).then((confirmed) => {
                 if (confirmed) {
                     this.$wire.{{ $method }}({{ $recordId }}, item.id);
                     this.value = item.id;
                 }
             });
         },
     }"
     x-on:keydown.escape="open = false"
     x-on:click.outside="open = false"
     x-on:scroll.window="open = false"
     x-on:resize.window="open = false">
    <button type="button" x-ref="trigger" class="rk-ss__btn" x-on:click="toggle()" aria-haspopup="listbox" x-bind:aria-expanded="open">
        <i class="rk-ss__dot" x-show="picked && picked.color" x-bind:style="picked && picked.color ? 'background:' + picked.color : ''"></i>
        <span x-text="picked ? picked.label : '—'"></span>
        <x-heroicon-o-chevron-down class="rk-ss__chevron" x-bind:class="{ 'rotate-180': open }" />
    </button>

    <ul class="rk-ss__list" role="listbox" x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-bind:style="`top: ${pos.top}px; left: ${pos.left}px; min-width: ${Math.max(pos.minWidth, 160)}px`">
        <template x-for="item in items" :key="String(item.id)">
            <li role="option" x-bind:aria-selected="item.id === value">
                <button type="button" class="rk-ss__item" x-bind:class="{ 'is-selected': item.id === value }" x-on:click="choose(item)">
                    <i class="rk-ss__dot" x-show="item.color" x-bind:style="item.color ? 'background:' + item.color : ''"></i>
                    <span x-text="item.label"></span>
                    <x-heroicon-s-check class="rk-ss__check" x-show="item.id === value" />
                </button>
            </li>
        </template>
    </ul>
</div>

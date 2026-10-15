{{-- Chips cell (users -> roles, roles -> permissions): first two roles as chips, the rest behind a
     clickable "+N" that opens a popup listing every role. The popup is
     position:fixed so the table's scroll box never clips it. --}}
@php($roles = $getRecord()->{\Illuminate\Support\Str::before($getName(), '.')}->pluck('name')->values())
<div class="rk-roles"
     x-data="{
         open: false,
         pos: { top: 0, left: 0 },
         toggle() {
             this.open = ! this.open;
             if (this.open) {
                 const r = this.$refs.more.getBoundingClientRect();
                 this.pos = { top: r.bottom + 6, left: Math.max(8, Math.min(r.left, window.innerWidth - 400)) };
                this.$nextTick(() => {
                    const h = this.$refs.pop.offsetHeight;
                    if (r.bottom + 6 + h > window.innerHeight - 8) { this.pos.top = Math.max(8, r.top - 6 - h); }
                });
             }
         },
     }"
     x-on:keydown.escape="open = false"
     x-on:click.outside="open = false"
     x-on:scroll.window="open = false"
     x-on:resize.window="open = false">
    @foreach ($roles->take(2) as $name)
        <span class="rk-roles__chip">{{ $name }}</span>
    @endforeach

    @if ($roles->count() > 2)
        <button type="button" x-ref="more" class="rk-roles__more" x-on:click.prevent.stop="toggle()"
                aria-haspopup="true" x-bind:aria-expanded="open" title="{{ __('Show all') }}">+{{ $roles->count() - 2 }}</button>

        <div class="rk-roles__pop" x-ref="pop" x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-on:click.stop
             x-bind:style="`top: ${pos.top}px; left: ${pos.left}px`">
            <b>{{ $getLabel() }} <small>{{ $roles->count() }}</small></b>
            <div>
                @foreach ($roles as $name)
                    <span class="rk-roles__chip">{{ $name }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>

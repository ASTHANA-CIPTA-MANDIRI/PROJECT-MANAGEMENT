@php
    $roleOf = fn ($organization) => $organization->pivot?->role ? __(ucfirst($organization->pivot->role)) : null;
    $tint = fn ($organization) => ['blue', 'green', 'yellow', 'red'][$organization->id % 4];
    $initial = fn ($organization) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($organization->name, 0, 1));
@endphp
<div class="organization-switcher px-6 pb-4 flex flex-col gap-2">
    @if ($current ?? $organizations->first())
        <button type="button" class="organization-switcher-mini" x-data x-on:click="$store.sidebar.open()" title="{{ ($current ?? $organizations->first())->name }}">
        <x-heroicon-o-office-building class="w-6 h-6" />
        </button>
    @endif
    @if ($organizations->isEmpty())
        {{-- No organization membership at all: nothing to switch between. --}}
    @elseif ($organizations->count() === 1)
        @php($only = $organizations->first())
        <div class="rk-os">
            <span class="rk-os__label">{{ __('Organization') }}</span>
            <div class="rk-os__card">
                <span class="rk-os__tile rk-os__tile--{{ $tint($only) }}">{{ $initial($only) }}</span>
                <span class="rk-os__text"><b>{{ $only->name }}</b>@if ($roleOf($only))<small>{{ $roleOf($only) }}</small>@endif</span>
            </div>
        </div>
    @else
        {{-- The posted id is never trusted: switchOrganization() re-verifies membership. --}}
        <div class="rk-os"
             x-data="{ open: false }"
             x-on:keydown.escape="open = false"
             x-on:click.outside="open = false">
            <span class="rk-os__label" id="organization-switcher-label">{{ __('Organization') }}</span>

            <button type="button" x-ref="trigger" class="rk-os__card rk-os__card--button" x-bind:class="{ 'is-open': open }" x-on:click="open = ! open"
                    aria-haspopup="listbox" x-bind:aria-expanded="open" aria-labelledby="organization-switcher-label">
                <span class="rk-os__tile {{ $current ? 'rk-os__tile--'.$tint($current) : '' }}">{{ $current ? $initial($current) : '?' }}</span>
                <span class="rk-os__text"><b>{{ $current?->name ?? __('Organization') }}</b>@if ($current && $roleOf($organizations->firstWhere('id', $current->id)))<small>{{ $roleOf($organizations->firstWhere('id', $current->id)) }}</small>@endif</span>
                <x-heroicon-o-chevron-down class="rk-os__chevron" x-bind:class="{ 'rotate-180': open }" />
            </button>

            <ul class="rk-os__list" role="listbox" x-show="open" x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-1 scale-95">
                @foreach ($organizations as $organization)
                    <li role="option" aria-selected="{{ $current?->is($organization) ? 'true' : 'false' }}">
                        <button type="button" class="rk-os__item {{ $current?->is($organization) ? 'is-selected' : '' }}"
                                x-on:click="open = false; $wire.switchOrganization({{ $organization->id }})">
                            <span class="rk-os__tile rk-os__tile--{{ $tint($organization) }}">{{ $initial($organization) }}</span>
                            <span class="rk-os__text"><b>{{ $organization->name }}</b>@if ($roleOf($organization))<small>{{ $roleOf($organization) }}</small>@endif</span>
                            @if ($current?->is($organization))<x-heroicon-s-check class="rk-os__check" />@endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Phase 5.4.4F (Option B): only offered to a user with zero
         organization memberships — OrganizationPolicy::create() denies
         everyone else, so hiding this link for them keeps the UI honest
         about what the backend actually allows, rather than offering a
         link that always 403s for an existing member/owner/admin. --}}
    @can('create', \App\Models\Organization::class)
        <a
            href="{{ route('filament.pages.create-organization') }}"
            class="rk-org-create"
        >
            + {{ __('Create organization') }}
        </a>
    @endcan
</div>

@php($fmt = fn (float $hours) => rtrim(rtrim(number_format($hours, 1, ',', '.'), '0'), ','))
<x-filament::header>
    <x-slot name="heading">{{ $heading }}</x-slot>
</x-filament::header>

<div class="rk-ts">
    <div class="rk-pf__stats rk-ts__stats">
        <div class="rk-pf__stat rk-pf__stat--blue"><span><x-heroicon-o-clock class="h-6 w-6" /></span><div><b>{{ $fmt($summary['week']) }} {{ __('hrs') }}</b><small>{{ __('This week') }}</small></div></div>
        <div class="rk-pf__stat rk-pf__stat--green"><span><x-heroicon-o-calendar class="h-6 w-6" /></span><div><b>{{ $fmt($summary['month']) }} {{ __('hrs') }}</b><small>{{ __('This month') }}</small></div></div>
        <div class="rk-pf__stat rk-pf__stat--yellow"><span><x-heroicon-o-chart-bar class="h-6 w-6" /></span><div><b>{{ $fmt($summary['year']) }} {{ __('hrs') }}</b><small>{{ __('This year') }}</small></div></div>
        <div class="rk-pf__stat rk-pf__stat--red"><span><x-heroicon-o-badge-check class="h-6 w-6" /></span><div><b>{{ $summary['topActivity'] ?? '-' }}</b><small>{{ $summary['topActivity'] ? __('Top activity').' · '.$fmt($summary['topActivityHours']).' '.__('hrs') : __('Top activity') }}</small></div></div>
    </div>
</div>

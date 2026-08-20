@props(['sprint', 'nextSprint' => null])

<div class="w-full flex flex-col gap-1">
    <div class="w-full flex items-center gap-2">
        <span class="bg-danger-500 px-2 py-1 rounded text-white text-sm">{{ $sprint->name }}</span>
        <span class="text-xs text-gray-400">
            {{ __('Started at:') }} {{ $sprint->started_at->format(__('Y-m-d')) }} -
            {{ __('Ends at:') }} {{ $sprint->ends_at->format(__('Y-m-d')) }}
            @if ($sprint->remaining > 0)
                - {{ __('Remaining:') }} {{ $sprint->remaining }} {{ __('days') }}
            @elseif ($sprint->remaining !== null)
                - {{ __('Overdue') }}
            @endif
        </span>
    </div>
    @if ($nextSprint)
        <span class="text-xs text-primary-500 font-medium">
            {{ __('Next sprint:') }} {{ $nextSprint->name }} -
            {{ __('Starts at:') }} {{ $nextSprint->starts_at->format(__('Y-m-d')) }}
            ({{ __('in') }} {{ $nextSprint->starts_at->diffForHumans() }})
        </span>
    @endif
</div>

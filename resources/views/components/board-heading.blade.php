@props(['title', 'projectName' => null])

<div class="w-full flex flex-col gap-1">
    <a href="{{ route('filament.pages.board') }}"
       class="text-primary-500 text-xs font-medium hover:underline">{{ __('Back to board') }}</a>
    <div class="flex flex-col gap-1">
        @if ($projectName)
            <span>{{ $title }} - {{ $projectName }}</span>
        @else
            <span>{{ $title }}</span>
            <span class="text-xs text-gray-400">
                {{ __('Only default statuses are listed when no projects selected') }}
            </span>
        @endif
    </div>
</div>

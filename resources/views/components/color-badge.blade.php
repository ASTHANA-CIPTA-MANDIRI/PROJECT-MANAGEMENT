@props(['color', 'label' => null, 'title' => null, 'class' => ''])

<div class="flex items-center gap-2 {{ $class }}">
    <span
        class="filament-tables-color-column relative flex h-6 w-6 rounded-md"
        style="background-color: {{ $color }}"
        @if ($title) title="{{ $title }}" @endif
    ></span>
    @if ($label)
        <span>{{ $label }}</span>
    @endif
</div>

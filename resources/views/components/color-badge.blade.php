@props(['color', 'label' => null, 'title' => null, 'class' => '', 'pill' => false, 'icon' => null])

@php($safeColor = \App\Support\Colors::safe($color))

@if ($pill)
    {{-- Pill variant: soft tint of the status color (hex + 26 = ~15% alpha) with
         darkened same-hue text. $safeColor is always a validated 6-digit hex, so
         appending an alpha pair is safe. The plain `color` is a fallback for
         browsers without color-mix(). --}}
    <span
        class="color-pill {{ $class }}"
        style="background-color: {{ $safeColor }}26; color: #374151; color: color-mix(in srgb, {{ $safeColor }} 45%, #000);"
        @if ($title) title="{{ $title }}" @endif
    >
        @if ($icon)
            <x-icon class="color-pill-icon" name="{{ $icon }}" />
        @endif
        <span class="color-pill-label">{{ $label ?? $title }}</span>
    </span>
@else
    <div class="flex items-center gap-2 {{ $class }}">
        <span
            class="filament-tables-color-column relative flex h-6 w-6 rounded-md"
            style="background-color: {{ $safeColor }}"
            @if ($title) title="{{ $title }}" @endif
        ></span>
        @if ($label)
            <span>{{ $label }}</span>
        @endif
    </div>
@endif

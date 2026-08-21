<x-filament::page>

    @if ($plainTextToken)
        <div class="w-full flex flex-col gap-2 bg-warning-500 border border-warning-600 text-white py-3 px-4 text-sm rounded-lg">
            <span class="font-medium">{{ __('Copy your new token now') }}</span>
            <span class="font-normal">{{ __('It is stored hashed, so this is the only time it can be shown.') }}</span>
            {{-- Read-only input rather than plain text: it selects in one click,
                 and the value is escaped as an attribute either way. --}}
            <input type="text"
                   readonly
                   onfocus="this.select()"
                   class="w-full py-1 px-3 rounded-lg text-gray-900 border-warning-600 font-mono text-xs"
                   value="{{ $plainTextToken }}" />
        </div>
    @endif

    {{ $this->table }}

</x-filament::page>

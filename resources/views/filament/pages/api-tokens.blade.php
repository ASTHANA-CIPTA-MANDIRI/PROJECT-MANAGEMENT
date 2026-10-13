<x-filament::page>

    @if ($plainTextToken)
        <div class="rk-notice rk-notice--success rk-token-notice">
            <span class="font-medium">{{ __('Copy your new token now') }}</span>
            <span class="font-normal">{{ __('It is stored hashed, so this is the only time it can be shown.') }}</span>
            {{-- Read-only input rather than plain text: it selects in one click,
                 and the value is escaped as an attribute either way. --}}
            <input type="text"
                   readonly
                   onfocus="this.select()"
                   class="rk-token-input"
                   value="{{ $plainTextToken }}" />
        </div>
    @endif

    {{ $this->table }}

</x-filament::page>

<x-filament-breezy::auth-card action="authenticate">

    <div class="w-full flex justify-center lg:hidden">
        <x-filament::brand />
    </div>

    <div>
        <h2 class="font-bold tracking-tight text-center text-2xl">
            {{ __('Two-factor authentication') }}
        </h2>
        <p class="mt-2 text-sm text-center">
            {{ $usingRecoveryCode
                ? __('Enter one of your recovery codes to continue.')
                : __('Enter the code from your authenticator app to continue.') }}
        </p>
    </div>

    {{ $this->form }}

    <x-filament::button type="submit" class="w-full">
        {{ __('Verify') }}
    </x-filament::button>

    <div class="text-center">
        <button type="button" wire:click="toggleRecoveryCode"
                class="text-sm text-gray-500 hover:text-primary-500 hover:underline">
            {{ $usingRecoveryCode
                ? __('Use an authenticator code instead')
                : __('Use a recovery code instead') }}
        </button>
    </div>

    {{-- Auto-submit once a 6-digit authenticator code is fully typed, so the user
         doesn't have to press Enter or click Verify. Skipped in recovery-code mode
         (variable length, no reliable trigger length) via the field's own
         autocomplete flag ("one-time-code" vs "off"). Purely additive: it clicks
         the existing submit button, the same action Enter already triggers —
         manual submit and Enter-key submission keep working unchanged.

         A bare inline <script> tag only runs when it's part of the HTML the
         browser parses on initial navigation. The sibling screen
         (vendor/filament-breezy/two-factor.blade.php) reaches this same
         markup via a Livewire DOM morph instead, where a plain <script> is
         inserted but never executed — confirmed with Playwright against the
         live app (the script was present in the post-morph DOM, but a
         console.log at the top of it never fired). This component's first
         render is a normal full-page GET, where that specific failure mode
         doesn't apply, but a wrong-code retry re-renders it via the same kind
         of Livewire morph, so the identical Alpine-based approach is used
         here too rather than relying on a bare <script> staying reliable
         across every re-render. Livewire re-initializes Alpine on morphed-in
         nodes, which is the standard way Livewire 2 + Alpine stay wired up
         across re-renders. Still just polls the field's raw value and clicks
         the existing button; no event binding, no Livewire/PHP change. --}}
    <div
        x-data="{}"
        x-init="
            if (!window.__twoFactorAutoSubmitPolling) {
                window.__twoFactorAutoSubmitPolling = true;
                let lastAutoSubmitted = null;
                setInterval(() => {
                    let input = document.querySelector('input[autocomplete=one-time-code]');
                    if (!input) return;
                    if (input.value.length !== 6) { lastAutoSubmitted = null; return; }
                    if (input.value === lastAutoSubmitted) return;
                    lastAutoSubmitted = input.value;
                    let form = input.closest('form');
                    let button = form && form.querySelector('button[type=submit]');
                    if (button && !button.disabled) button.click();
                }, 300);
            }
        "
    ></div>

</x-filament-breezy::auth-card>

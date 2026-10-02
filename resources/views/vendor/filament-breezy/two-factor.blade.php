<x-filament-breezy::auth-card action="authenticate">

    <div class="w-full flex justify-center lg:hidden">
        <x-filament::brand />
    </div>

    <div>
        <h2 class="font-bold tracking-tight text-center text-2xl">
            {{ $this->usingRecoveryCode ? __('filament-breezy::default.two_factor.recovery.heading') : __('filament-breezy::default.two_factor.heading') }}
        </h2>
        <p class="mt-2 text-sm text-center">
            {{ $this->usingRecoveryCode ? __('filament-breezy::default.two_factor.recovery.description') : __('filament-breezy::default.two_factor.description') }} <a class="text-primary-600" href="{{route('filament.auth.login')}}">
                {{ __('filament-breezy::default.two_factor.back_to_login_link') }}
            </a>
        </p>
    </div>

    {{ $this->twoFactorForm }}

    <x-filament::button type="submit" class="w-full">
        {{ __('filament::login.buttons.submit.label') }}
    </x-filament::button>

    <div class="text-center">
        {{ $this->usingRecoveryCode ? '' : __('filament-breezy::default.two_factor.recovery_code_text') }}
        <a x-data @click="$wire.toggleRecoveryCode()" class="text-primary-600 hover:text-primary-700" href="#">{{$this->usingRecoveryCode ? __('filament-breezy::default.cancel') : __('filament-breezy::default.two_factor.recovery_code_link') }}</a>
    </div>

    {{-- Auto-submit once a 6-digit authenticator code is fully typed, so the user
         doesn't have to press Enter or click Verify. Skipped in recovery-code mode
         (variable length, no reliable trigger length) via the field's own
         autocomplete flag ("one-time-code" vs "off"). Purely additive: it clicks
         the existing submit button, the same action Enter already triggers —
         manual submit and Enter-key submission keep working unchanged.

         This step of the login form is reached via a Livewire DOM morph (the
         password step posts, Livewire patches the existing component in place
         to swap in this markup) rather than a full page navigation. A plain
         inline <script> tag inserted that way is parsed but never executed —
         browsers don't run <script> elements that arrive via DOM
         patching/innerHTML, only ones present when the page was first parsed.
         Confirmed via Playwright against the live app: the script was present
         in the DOM after the morph, but a console.log placed at the very top
         of it never fired. An x-init Alpine hook doesn't have that problem —
         Livewire re-initializes Alpine on morphed-in nodes, which is the
         standard way Livewire 2 + Alpine components stay interactive across
         re-renders — so the polling logic is wired up through that instead of
         a bare <script> tag. Still just polls the field's raw value and
         clicks the existing button; no event binding, no Livewire/PHP change. --}}
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

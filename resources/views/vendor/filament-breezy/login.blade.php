<x-filament-breezy::auth-card action="authenticate">

    <div class="w-full flex justify-center">
        <x-filament::brand />
    </div>

    <h2 class="font-bold tracking-tight text-center text-2xl">
        {{ __('filament::login.heading') }}
    </h2>

    @if(config('system.login_form.is_enabled'))
    <div>
        @if(config("filament-breezy.enable_registration"))
        <p class="mt-2 text-sm text-center">
            {{ __('filament-breezy::default.or') }}
            <a class="text-primary-600" href="{{route(config('filament-breezy.route_group_prefix').'register')}}">
                {{ strtolower(__('filament-breezy::default.registration.heading')) }}
            </a>
        </p>
        @endif
    </div>

    {{ $this->form }}

    <x-filament::button type="submit" class="w-full">
        {{ __('filament::login.buttons.submit.label') }}
    </x-filament::button>

    <div class="text-center">
        <a class="text-primary-600 hover:text-primary-700" href="{{route(config('filament-breezy.route_group_prefix').'password.request')}}">{{ __('filament-breezy::default.login.forgot_password_link') }}</a>
    </div>
    @endif

    @if(config('filament-socialite.enabled'))
    <x-filament-socialite::buttons />
    @endif

    <div class="text-center mt-4">
        <span class="text-sm text-gray-500">Rencanakan by Ethes Digital</span>
    </div>

    {{-- Live countdown for the login throttle message ("try again in N seconds").
         Purely additive: it only rewrites the seconds in the existing message,
         never touches the login logic — if it finds nothing it does nothing. --}}
    <script>
        (function () {
            var RE = /(\d+)\s*(detik|seconds?|seconde?s?|segundos?|sekund(?:er)?)/i;
            var timer = null;

            function findMsg() {
                var scope = document.querySelector('form') || document.body;
                return Array.prototype.slice.call(scope.querySelectorAll('p, span, div'))
                    .filter(function (el) { return el.children.length === 0 && RE.test(el.textContent); })[0];
            }

            function start() {
                var el = findMsg();
                if (!el) return;
                var m = el.textContent.match(RE);
                if (!m) return;
                var secs = parseInt(m[1], 10);
                var template = el.textContent, unit = m[2];
                if (timer) { clearInterval(timer); timer = null; }
                if (secs <= 0) return;
                timer = setInterval(function () {
                    secs -= 1;
                    el.textContent = template.replace(RE, Math.max(secs, 0) + ' ' + unit);
                    if (secs <= 0) { clearInterval(timer); timer = null; }
                }, 1000);
            }

            document.addEventListener('livewire:load', function () {
                if (window.Livewire) {
                    Livewire.hook('message.processed', function () { setTimeout(start, 50); });
                }
            });
            document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 300); });
        })();
    </script>

    {{-- Show/hide password toggle (eye icon). Additive: injects a button into
         the password field's wrapper, toggling its type. Never touches login. --}}
    <script>
        (function () {
            var EYE = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            var EYE_OFF = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

            function enhance() {
                document.querySelectorAll('form input[type="password"]').forEach(function (input) {
                    var wrap = input.parentElement;
                    if (!wrap || wrap.querySelector('.pw-reveal-btn')) return;

                    wrap.style.position = 'relative';
                    input.style.paddingRight = '2.5rem';

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pw-reveal-btn';
                    btn.setAttribute('aria-label', 'Show password');
                    btn.innerHTML = EYE;
                    btn.style.cssText = 'position:absolute;top:50%;right:.6rem;transform:translateY(-50%);background:none;border:0;padding:0;cursor:pointer;color:#6b7280;display:flex;';
                    btn.addEventListener('click', function () {
                        var show = input.getAttribute('type') === 'password';
                        input.setAttribute('type', show ? 'text' : 'password');
                        btn.innerHTML = show ? EYE_OFF : EYE;
                    });
                    wrap.appendChild(btn);
                });
            }

            document.addEventListener('livewire:load', function () {
                if (window.Livewire) {
                    Livewire.hook('message.processed', function () { setTimeout(enhance, 50); });
                }
            });
            document.addEventListener('DOMContentLoaded', function () { setTimeout(enhance, 300); });
        })();
    </script>
</x-filament-breezy::auth-card>
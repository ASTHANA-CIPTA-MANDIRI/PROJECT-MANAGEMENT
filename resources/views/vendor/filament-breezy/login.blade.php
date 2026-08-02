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
</x-filament-breezy::auth-card>
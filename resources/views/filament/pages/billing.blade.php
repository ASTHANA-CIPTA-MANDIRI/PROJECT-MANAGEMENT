<x-filament::page>

    @php($sub = $this->panel())
    @php($plans = $sub['plans'])
    @php($tz = config('app.display_timezone'))
    @php($firstPlan = array_key_first($plans))
    @php($savings = $this->yearlySavingsPercent($plans))
    @php($unitLabels = ['monthly' => __('/month'), 'yearly' => __('/year')])
    @php($showCard = $sub['checkoutEnabled'] && $plans !== [] && ! ($sub['paid'] && $sub['unlimited']))

    {{-- Page-scoped styles: every selector starts with .rk-bl so nothing leaks to other
         pages. Inline on purpose - this page must not touch the shared stylesheet. --}}
    <style>
        .rk-bl { display: flex; width: 100%; flex-direction: column; align-items: center; gap: 1.5rem; }
        .rk-bl__sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .rk-bl [x-cloak] { display: none !important; }
        .rk-bl__status { display: flex; width: 100%; max-width: 40rem; flex-direction: column; gap: .75rem; border-radius: 1.5rem; background: #fff; padding: 1.25rem 1.5rem; box-shadow: 0 2px 14px rgba(15, 23, 42, .06); }
        .rk-bl__row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .rk-bl__row b { display: block; font-size: .875rem; font-weight: 600; color: #111827; }
        .rk-bl__row small { display: block; font-size: .75rem; color: #6b7280; }
        .rk-bl__chip { flex-shrink: 0; border-radius: 9999px; padding: .25rem .75rem; font-size: .75rem; font-weight: 500; background: #eceef1; color: #6f767e; }
        .rk-bl__chip--blue { background: #e7f0fb; color: #3b82d6; }
        .rk-bl__chip--yellow { background: #fdf3de; color: #b8841f; }
        .rk-bl__chip--red { background: #fcebe6; color: #d0644a; }
        .rk-bl__chip--green { background: #e3f5ea; color: #3fae6a; }
        .rk-bl__note { margin: 0; font-size: .875rem; color: #6b7280; }
        .rk-bl__note--alert { color: #b8841f; }
        .rk-bl__error { margin: 0; font-size: .875rem; color: #d0644a; }
        .rk-bl__toggle { display: inline-flex; gap: .25rem; border-radius: 9999px; background: #eceef1; padding: .25rem; }
        .rk-bl__toggle button { display: inline-flex; height: 2.5rem; align-items: center; gap: .5rem; border: 0; border-radius: 9999px; background: transparent; padding: 0 1.25rem; font-size: .875rem; color: #6f767e; cursor: pointer; transition: background-color .15s, color .15s; }
        .rk-bl__toggle button[aria-pressed="true"] { background: #fff; color: #111827; box-shadow: 0 1px 4px rgba(15, 23, 42, .12); }
        .rk-bl__save { border-radius: 9999px; background: #e3f5ea; padding: .125rem .5rem; font-size: .75rem; font-weight: 500; color: #3fae6a; }
        .rk-bl__card { display: flex; width: 100%; max-width: 26rem; flex-direction: column; gap: 1.25rem; border-radius: 1.5rem; background: #fff; padding: 2rem; box-shadow: 0 2px 14px rgba(15, 23, 42, .06); }
        .rk-bl__card h3 { margin: 0; font-size: 1.125rem; font-weight: 600; color: #111827; }
        .rk-bl__price { display: flex; flex-wrap: wrap; align-items: baseline; gap: .375rem; }
        .rk-bl__price b { font-size: 2.25rem; font-weight: 700; line-height: 1.1; color: #111827; }
        .rk-bl__price span { font-size: .875rem; color: #6b7280; }
        .rk-bl__duration { margin: 0; font-size: .75rem; color: #6b7280; }
        .rk-bl__perks { display: flex; margin: 0; flex-direction: column; gap: .625rem; padding: 0; list-style: none; font-size: .875rem; color: #374151; }
        .rk-bl__perks li { display: flex; align-items: flex-start; gap: .5rem; }
        .rk-bl__perks svg { margin-top: .125rem; height: 1.125rem; width: 1.125rem; flex-shrink: 0; color: #72cc90; }
        .rk-bl__form { margin: 0; }
        .rk-bl__btn { display: inline-flex; height: 3rem; width: 100%; align-items: center; justify-content: center; gap: .5rem; border: 0; border-radius: 9999px; background: #3b82d6; padding: 0 1.5rem; font-size: .875rem; font-weight: 400; color: #fff; text-decoration: none; cursor: pointer; transition: filter .15s; }
        .rk-bl__btn:hover:not(:disabled) { filter: brightness(.94); }
        .rk-bl__btn:disabled { cursor: default; opacity: .6; }
        .rk-bl__btn--ghost { box-sizing: border-box; height: 2.5rem; min-width: 0; width: auto; flex-shrink: 0; white-space: nowrap; line-height: 1; text-align: center; border: 1px solid #cfe0f6; background: #fff; padding: 0 1.25rem; color: #3b82d6; }
        .rk-bl__btn--ghost:hover:not(:disabled) { background: #e7f0fb; filter: none; }
        .rk-bl__pending { display: flex; flex-direction: column; align-items: center; gap: .75rem; border-top: 1px solid #eceef1; padding-top: 1rem; text-align: center; }
        .rk-bl__pending-text { overflow-wrap: anywhere; }
        .rk-bl__pending-text b { display: block; font-size: .875rem; font-weight: 600; color: #111827; }
        .rk-bl__pending-text small { display: block; margin-top: .125rem; font-size: .75rem; color: #6b7280; }
        .rk-bl__pending .rk-bl__note { text-align: center; }
        @media (max-width: 639px) {
            .rk-bl__card { padding: 1.5rem; }
            .rk-bl__price b { font-size: 1.875rem; }
            .rk-bl__row { align-items: flex-start; }
        }
    </style>

    <div class="rk-bl" @if ($showCard) x-data="{ period: @js($firstPlan) }" @endif>

        <h1 class="rk-bl__sr">{{ __('Subscription') }}</h1>

        {{-- Status: trial and paid are separate facts, for every member. --}}
        <section class="rk-bl__status">
            @if ($sub['locked'])
                <p class="rk-bl__note rk-bl__note--alert">
                    {{ $sub['canManage'] ? __('Your trial has ended. Subscribe to keep using this organization.') : __('Your trial has ended. Ask an organization owner or admin to subscribe.') }}
                </p>
            @endif

            <div class="rk-bl__row">
                <div>
                    <b>{{ __('Paid subscription') }}</b>
                    <small>
                        @if ($sub['unlimited'])
                            {{ __('No time limit') }}
                        @elseif ($sub['paidUntil'])
                            {{ __('Active until :date', ['date' => $sub['paidUntil']->timezone($tz)->format('d M Y H:i')]) }}
                        @endif
                    </small>
                </div>
                <span class="rk-bl__chip rk-bl__chip--{{ $sub['paid'] ? 'blue' : 'gray' }}">{{ $sub['paid'] ? __('Subscribed') : __('Not subscribed') }}</span>
            </div>

            <div class="rk-bl__row">
                <div>
                    <b>{{ __('Trial period') }}</b>
                    <small>
                        @if ($sub['trialEndsAt'])
                            {{ __('Trial until :date', ['date' => $sub['trialEndsAt']->timezone($tz)->format('d M Y H:i')]) }}
                        @endif
                    </small>
                </div>
                <span class="rk-bl__chip rk-bl__chip--{{ $sub['trial']['tone'] }}">{{ $sub['trial']['label'] }}</span>
            </div>
        </section>

        @if (! $sub['canManage'])
            {{-- Plain Member: no card, no prices, no button. --}}
            <p class="rk-bl__note">{{ __('Ask an organization owner or admin to subscribe or renew.') }}</p>
        @elseif (! $sub['checkoutEnabled'] || $plans === [])
            <p class="rk-bl__note">{{ __('Payment is not available yet') }}</p>
        @elseif ($sub['paid'] && $sub['unlimited'])
            <p class="rk-bl__note">{{ __('Your subscription has no end date, so there is nothing to renew.') }}</p>
        @else
            @if (count($plans) > 1)
                <div class="rk-bl__toggle" role="group" aria-label="{{ __('Billing period') }}">
                    @foreach ($plans as $code => $plan)
                        <button type="button" x-on:click="period = @js($code)" x-bind:aria-pressed="(period === @js($code)).toString()" aria-pressed="{{ $code === $firstPlan ? 'true' : 'false' }}">
                            {{ __($plan['name']) }}
                            @if ($code === 'yearly' && $savings !== null)
                                <span class="rk-bl__save">{{ __('Save :percent%', ['percent' => $savings]) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            <section class="rk-bl__card">
                <h3>{{ config('app.name') }}</h3>

                @foreach ($plans as $code => $plan)
                    <div @if (! $loop->first) x-cloak @endif x-show="period === @js($code)">
                        <div class="rk-bl__price">
                            <b>{{ \App\Support\SubscriptionPanel::formatRupiah($plan['amount']) }}</b>
                            <span>{{ $unitLabels[$code] ?? '' }}</span>
                        </div>
                        <p class="rk-bl__duration">
                            {{ trans_choice(':count day|:count days', $plan['duration_days'], ['count' => $plan['duration_days']]) }}
                            @if ($sub['paidUntil'])
                                &middot; {{ __('Renews from :date', ['date' => $sub['paidUntil']->timezone($tz)->format('d M Y')]) }}
                            @endif
                        </p>
                    </div>
                @endforeach

                <ul class="rk-bl__perks">
                    <li><x-heroicon-o-check class="h-5 w-5" /> {{ __('Full access to the organization\'s projects, tickets, sprints and epics') }}</li>
                    <li><x-heroicon-o-check class="h-5 w-5" /> {{ __('Every member of the organization can keep working') }}</li>
                </ul>

                {{-- Plain HTML form POST (not a Livewire call) so the browser follows the redirect
                     to DOKU's hosted page. The plan code follows the toggle; the server re-resolves
                     the price and the Organization from it. --}}
                <form method="POST" action="{{ route('organizations.subscribe') }}" class="rk-bl__form" x-on:submit="$el.querySelector('[type=submit]').disabled = true" x-on:pageshow.window="$el.querySelector('[type=submit]').disabled = false">
                    @csrf
                    <input type="hidden" name="plan" value="{{ $firstPlan }}" x-bind:value="period">
                    <button type="submit" class="rk-bl__btn">{{ $sub['paid'] ? __('Renew') : __('Subscribe now') }}</button>
                </form>

                @error('plan')
                    <p class="rk-bl__error">{{ $message }}</p>
                @enderror

                @if ($sub['pendingInvoice'])
                    <div class="rk-bl__pending">
                        <div class="rk-bl__pending-text">
                            <b>{{ __('You have an unfinished payment') }}</b>
                            <small>
                                {{ ($planName = \App\Support\SubscriptionPlans::find($sub['pendingInvoice']->plan_code)['name'] ?? null) ? __($planName) : $sub['pendingInvoice']->plan_code }}
                                &middot; {{ \App\Support\SubscriptionPanel::formatRupiah($sub['pendingInvoice']->amount) }}
                            </small>
                        </div>
                        <a href="{{ $sub['pendingInvoice']->doku_payment_url }}" rel="noopener" class="rk-bl__btn rk-bl__btn--ghost">{{ __('Continue payment') }}</a>
                        <p class="rk-bl__note">{{ __('Payment is being processed, usually 5 minutes at most.') }}</p>
                    </div>
                @endif
            </section>
        @endif

    </div>

</x-filament::page>

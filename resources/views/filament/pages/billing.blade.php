<x-filament::page>

    @php($sub = $this->panel())
    @php($plans = $sub['plans'])
    @php($tz = config('app.display_timezone'))
    @php($firstPlan = array_key_first($plans))
    @php($savings = $this->yearlySavingsPercent($plans))
    @php($unitLabels = ['monthly' => __('/month'), 'yearly' => __('/year')])
    @php($showCard = $sub['checkoutEnabled'] && $plans !== [] && ! ($sub['paid'] && $sub['unlimited']))

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

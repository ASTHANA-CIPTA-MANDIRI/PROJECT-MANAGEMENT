<x-filament::page>
    @php
        $stats = $this->platformStats();
        $growth = $this->organizationGrowth();
        $status = $this->organizationStatus();
        $activity = $this->recentPlatformActivity();
        $actions = $this->quickActions();
        $detail = $this->organizationDetail();
    @endphp

    <div class="rk-pl">
        <div class="rk-pl__layout">
            {{-- Numbers and charts --}}
            <div class="rk-pl__top">
                <div class="rk-pl__stats">
                    @foreach ($stats as $stat)
                        <div class="rk-pl__stat">
                            <span class="rk-pl__stat-icon rk-pf__tile--{{ $stat['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-6 w-6" /></span>
                            <div>
                                <small>{{ $stat['label'] }}</small>
                                <b>{{ number_format($stat['total'], 0, ',', '.') }}</b>
                                @if ($stat['delta'] !== null && $stat['delta'] != 0)
                                    <em class="{{ $stat['delta'] < 0 ? 'is-down' : 'is-up' }}">{{ $stat['delta'] < 0 ? '↓' : '↑' }} {{ rtrim(rtrim(number_format(abs($stat['delta']), 1, ',', '.'), '0'), ',') }}% <span>{{ __('since last month') }}</span></em>
                                @else
                                    <em class="is-flat">{{ $stat['delta'] === null ? '—' : '0%' }} <span>{{ __('since last month') }}</span></em>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="rk-pl__charts">
                    <section class="rk-og__panel" wire:ignore
                             x-data="{
                                 months: '6',
                                 open: false,
                                 series: @js($growth),
                                 options: { '6': @js(__('Last 6 months')), '12': @js(__('Last 12 months')) },
                                 chart: null,
                                 build() {
                                     const run = () => {
                                         if (! window.rkCharts) { setTimeout(run, 50); return; }
                                         this.chart = window.rkCharts.bar(this.$refs.canvas, { label: @js(__('New organizations')), labels: this.series[this.months].labels, data: this.series[this.months].data, color: '#7ba7ec', hover: '#5b8fe0', top: true, thickness: 56 });
                                     };
                                     run();
                                 },
                                 pick(m) {
                                     this.months = m; this.open = false;
                                     if (this.chart) { this.chart.data.labels = this.series[m].labels; this.chart.data.datasets[0].data = this.series[m].data; this.chart.update(); }
                                 },
                             }"
                             x-init="build()" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                        <div class="rk-og__panel-head">
                            <div>
                                <h3>{{ __('Organization growth') }}</h3>
                                <p class="rk-pl__sub">{{ __('New organizations per month') }}</p>
                            </div>
                            <div class="rk-dd rk-dd--sm">
                                <button type="button" class="rk-dd__btn" x-on:click="open = ! open" aria-haspopup="listbox" x-bind:aria-expanded="open">
                                    <span class="rk-dd__label" x-text="options[months]"></span>
                                    <x-heroicon-o-chevron-down class="rk-dd__chevron" x-bind:class="{ 'rotate-180': open }" />
                                </button>
                                <ul class="rk-dd__list" role="listbox" x-show="open" x-transition.opacity x-cloak>
                                    <template x-for="(label, key) in options" :key="key">
                                        <li role="option"><button type="button" class="rk-dd__item" x-bind:class="{ 'is-selected': months == key }" x-on:click="pick(key)" x-text="label"></button></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        <div class="rk-pl__canvas"><canvas x-ref="canvas"></canvas></div>
                    </section>

                    <section class="rk-og__panel">
                        <div class="rk-og__panel-head">
                            <div>
                                <h3>{{ __('Organization status') }}</h3>
                                <p class="rk-pl__sub">{{ __('Distribution of organization states') }}</p>
                            </div>
                        </div>
                        <div class="rk-pl__donut">
                            <div class="rk-pl__donut-chart" wire:ignore
                                 x-data x-init="const cfg = @js(['labels' => array_column($status['rows'], 'label'), 'data' => array_column($status['rows'], 'count'), 'colors' => array_column($status['rows'], 'color'), 'legend' => false, 'center' => ['value' => $status['total'], 'caption' => __('Total organizations')]]); const run = () => window.rkCharts ? window.rkCharts.doughnut($el.querySelector('canvas'), cfg) : setTimeout(run, 50); run()">
                                <canvas></canvas>
                            </div>
                            <ul class="rk-pl__legend">
                                @foreach ($status['rows'] as $row)
                                    <li><i style="background: {{ $row['color'] }}"></i><span>{{ $row['label'] }}</span><b>{{ $row['count'] }}</b><small>{{ $row['percent'] }}%</small></li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                </div>

            </div>

            {{-- The real, unchanged Filament table --}}
            <div class="rk-pl__table">
                {{ $this->table }}
            </div>

            {{-- Right: latest activity, then quick actions --}}
            <aside class="rk-pl__side">
                <section class="rk-og__panel">
                    <div class="rk-og__panel-head"><h3>{{ __('Latest activity') }}</h3></div>
                    @forelse ($activity as $event)
                        <div class="rk-pl__act">
                            <span class="rk-pl__act-icon rk-pf__tile--{{ $event['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$event['icon']" class="h-5 w-5" /></span>
                            <div>
                                <b>{{ $event['title'] }}</b>
                                <small>{{ $event['text'] }}</small>
                            </div>
                            <time>{{ $event['at']->diffForHumans() }}</time>
                        </div>
                    @empty
                        <p class="rk-pf__empty">{{ __('No data found') }}</p>
                    @endforelse
                </section>

                <section class="rk-og__panel">
                    <div class="rk-og__panel-head"><h3>{{ __('Quick actions') }}</h3></div>
                    @foreach ($actions as $action)
                        @if ($action['href'])
                            <a href="{{ $action['href'] }}" class="rk-pl__qa">
                        @else
                            <button type="button" class="rk-pl__qa" x-data x-on:click="$wire.mountTableAction('createOrganization')">
                        @endif
                            <span class="rk-pl__act-icon rk-pf__tile--{{ $action['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$action['icon']" class="h-5 w-5" /></span>
                            <span class="rk-pl__qa-text"><b>{{ $action['label'] }}</b><small>{{ $action['hint'] }}</small></span>
                            <x-heroicon-o-chevron-right class="rk-pl__qa-chevron" />
                        @if ($action['href'])
                            </a>
                        @else
                            </button>
                        @endif
                    @endforeach
                </section>

                {{-- Detail of the clicked table row; nothing is shown until one is picked. --}}
                @if ($detail)
                    {{-- Below 1536px the panel is a slide-over sheet; this dims the page behind it. --}}
                    <div class="rk-pl__backdrop" wire:click="clearOrganizationSelection"></div>

                    <section class="rk-og__panel rk-pl__detail" wire:key="org-detail-{{ $detail['id'] }}"
                             x-data="{ tab: 'detail' }"
                             x-on:keydown.escape.window="if (! $event.defaultPrevented && ! document.querySelector('.filament-modal-window, .swal2-container')) { $wire.clearOrganizationSelection() }">
                        <header class="rk-pl__d-head">
                            <span class="rk-pl__d-tile rk-pf__tile--{{ $detail['tint'] }}">{{ $detail['initial'] }}</span>
                            <div>
                                <h3>{{ $detail['name'] }}</h3>
                                <span class="rk-og__chip rk-og__chip--{{ $detail['chip_tone'] }}">{{ $detail['chip_label'] }}</span>
                            </div>
                            <button type="button" class="rk-pl__d-close" wire:click="clearOrganizationSelection" title="{{ __('Close') }}"><x-heroicon-o-x class="h-5 w-5" /></button>
                        </header>

                        <nav class="rk-pl__d-tabs">
                            <button type="button" x-bind:class="{ 'is-active': tab === 'detail' }" x-on:click="tab = 'detail'">{{ __('Details') }}</button>
                            <button type="button" x-bind:class="{ 'is-active': tab === 'members' }" x-on:click="tab = 'members'">{{ __('Members') }}</button>
                            <button type="button" x-bind:class="{ 'is-active': tab === 'activity' }" x-on:click="tab = 'activity'">{{ __('Activity') }}</button>
                        </nav>

                        <div x-show="tab === 'detail'">
                            <h4>{{ __('Organization information') }}</h4>
                            <dl class="rk-pl__d-rows">
                                <div><dt><x-heroicon-o-office-building class="h-5 w-5" /> {{ __('Organization name') }}</dt><dd>{{ $detail['name'] }}</dd></div>
                                <div><dt><x-heroicon-o-user class="h-5 w-5" /> {{ __('Owner') }}</dt><dd>{{ $detail['owner_name'] }}@if ($detail['owner_email'])<small>{{ $detail['owner_email'] }}</small>@endif</dd></div>
                                <div><dt><x-heroicon-o-users class="h-5 w-5" /> {{ __('Member count') }}</dt><dd>{{ trans_choice(':count member|:count members', $detail['members_count'], ['count' => $detail['members_count']]) }}</dd></div>
                                <div><dt><x-heroicon-o-clock class="h-5 w-5" /> {{ __('Trial status') }}</dt><dd><span class="rk-og__chip rk-og__chip--{{ $detail['chip_tone'] }}">{{ $detail['chip_label'] }}</span><small>{{ $detail['ends_text'] }}</small></dd></div>
                                <div><dt><x-heroicon-o-shield-check class="h-5 w-5" /> {{ __('Full Access') }}</dt><dd><span class="rk-og__chip rk-og__chip--{{ $detail['full_access_tone'] }}">{{ $detail['full_access_label'] }}</span>@if ($detail['full_access_note'])<small>{{ $detail['full_access_note'] }}</small>@endif</dd></div>
                                <div><dt><x-heroicon-o-calendar class="h-5 w-5" /> {{ __('Created on') }}</dt><dd>{{ $detail['created_at'] }}</dd></div>
                            </dl>

                            <h4>{{ __('Plan & subscription') }}</h4>
                            <div class="rk-pl__plan rk-pl__plan--{{ $detail['plan']['tone'] }}">
                                <x-heroicon-o-exclamation class="h-5 w-5" />
                                <div><b>{{ $detail['plan']['title'] }}</b><p>{{ $detail['plan']['text'] }}</p></div>
                            </div>
                            @if ($detail['plan']['cta'])
                                <button type="button" class="rk-pl__cta" x-on:click="$wire.mountTableAction('editOrganization', '{{ $detail['id'] }}')">{{ $detail['plan']['cta'] }}</button>
                            @endif

                            <div class="rk-pl__d-actions">
                                <button type="button" x-on:click="$wire.mountTableAction('startSupportSession', '{{ $detail['id'] }}')"><x-heroicon-o-eye class="h-4 w-4" /> {{ __('Support session') }}</button>
                                @if ($detail['can_request_full_access'])
                                    <button type="button" x-on:click="$wire.mountTableAction('requestFullAccess', '{{ $detail['id'] }}')"><x-heroicon-o-shield-check class="h-4 w-4" /> {{ __('Request Full Access') }}</button>
                                @endif
                                @if ($detail['can_start_full_access'])
                                    <button type="button" x-on:click="$wire.mountTableAction('startFullAccess', '{{ $detail['id'] }}')"><x-heroicon-o-key class="h-4 w-4" /> {{ __('Start Full Access') }}</button>
                                @endif
                            </div>
                        </div>

                        <div x-show="tab === 'members'" x-cloak>
                            @forelse ($detail['members'] as $member)
                                <div class="rk-pl__d-member">
                                    <span class="rk-og__avatar rk-pf__tile--blue">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member['name'], 0, 1)) }}</span>
                                    <div><b>{{ $member['name'] }}</b><small>{{ $member['email'] }}</small></div>
                                    <span class="rk-og__chip rk-og__chip--gray">{{ $member['role'] }}</span>
                                </div>
                            @empty
                                <p class="rk-pf__empty">{{ __('No members') }}</p>
                            @endforelse
                            @if ($detail['members_more'] > 0)
                                <p class="rk-pl__d-more">{{ __('and :count more', ['count' => $detail['members_more']]) }}</p>
                            @endif
                        </div>

                        <div x-show="tab === 'activity'" x-cloak>
                            @foreach ($detail['activity'] as $event)
                                <div class="rk-pl__act">
                                    <span class="rk-pl__act-icon rk-pf__tile--{{ $event['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$event['icon']" class="h-5 w-5" /></span>
                                    <div><b>{{ $event['title'] }}</b><small>{{ $event['text'] }}</small></div>
                                    <time>{{ $event['at']->diffForHumans() }}</time>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-filament::page>

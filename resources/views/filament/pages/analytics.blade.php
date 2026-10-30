<x-filament::page class="rk-analytics-page">
    @php
        $projects = $this->accessibleProjects();
        $utilization = $this->utilization();
    @endphp

    {{-- ---------------------------------------------------------- selector --}}
    <div class="rk-an-filters">
        <div class="rk-an-field rk-dd"
             x-data="{ open: false, value: $wire.entangle('projectId'), options: @js($projects->all()) }"
             x-on:keydown.escape="open = false"
             x-on:click.outside="open = false">
            <span>{{ __('Project') }}</span>
            <button type="button" class="rk-dd__btn" x-on:click="open = ! open"
                    aria-haspopup="listbox" x-bind:aria-expanded="open">
                <span class="rk-dd__label" x-text="options[value] ?? '{{ __('No projects') }}'"></span>
                <x-heroicon-o-chevron-down class="rk-dd__chevron" x-bind:class="{ 'rotate-180': open }" />
            </button>
            <ul class="rk-dd__list" role="listbox" x-show="open" x-transition.opacity x-cloak>
                @forelse ($projects as $id => $name)
                    <li role="option">
                        <button type="button" class="rk-dd__item"
                                x-bind:class="{ 'is-selected': value == {{ $id }} }"
                                x-on:click="value = {{ $id }}; open = false">{{ $name }}</button>
                    </li>
                @empty
                    <li class="rk-dd__empty">{{ __('No projects') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    @if (! $this->currentProject())
        <x-filament::card class="rk-an-card">
            <p class="rk-an-empty rk-an-empty--hero">{{ __('Select a project to view its analytics.') }}</p>
        </x-filament::card>
    @else
        @php
            $in = $this->insights();
            $cycle = $in['cycle'];
            // One hue only (dark to light) for every chart.
            $blues = ['#1f5fae', '#3b82d6', '#6fa5e4', '#9fc3ee', '#c8dcf6'];
            $blueAt = fn (int $i) => $blues[min($i, count($blues) - 1)];
        @endphp

        @if (! $in['has_data'])
            <x-filament::card class="rk-an-card">
                <p class="rk-an-empty rk-an-empty--hero">{{ __('No tickets yet.') }}</p>
            </x-filament::card>
        @else
            @php
                $trend = $in['trend'];
                $trendCfg = [
                    'labels' => array_column($trend, 'label'),
                    'series' => [
                        ['label' => __('Created'), 'data' => array_column($trend, 'created'), 'color' => '#3b82d6'],
                        ['label' => __('Resolved'), 'data' => array_column($trend, 'resolved'), 'color' => '#6f767e'],
                    ],
                ];
                $createdTotal = array_sum(array_column($trend, 'created'));
                $resolvedTotal = array_sum(array_column($trend, 'resolved'));
                $net = $createdTotal - $resolvedTotal;
                $maxLoad = max(1, collect($in['workload'])->max(fn ($r) => $r['open'] + $r['done_30d']));
                $totalP = max(1, collect($in['priority'])->sum('count'));
                $typeTotal = collect($in['type'])->sum('count');
                $typeCfg = [
                    'labels' => array_column($in['type'], 'name'),
                    'data' => array_column($in['type'], 'count'),
                    'colors' => collect($in['type'])->keys()->map(fn ($i) => $blueAt($i))->all(),
                    'center' => ['value' => $typeTotal, 'caption' => __('open')],
                    'legend' => false,
                ];
                $hasSprints = count($in['sprints']) > 0;
            @endphp

            {{-- Free-form bento: cards of different sizes, no group containers. --}}
            <div class="rk-an-bento">

                {{-- created vs resolved: chart + side numbers --}}
                <x-filament::card class="rk-an-card rk-span-8">
                    <h3 class="rk-an-title">{{ __('Created vs resolved') }}</h3>
                    <p class="rk-an-sub">{{ __('Tickets created and finished per week, last 8 weeks') }}</p>

                    <div class="rk-an-split">
                        <div class="rk-an-chart" wire:ignore wire:key="trend-{{ $this->projectId }}"
                             x-data x-init="const cfg = @js($trendCfg); const run = () => window.rkCharts ? window.rkCharts.area($el.querySelector('canvas'), cfg) : setTimeout(run, 50); run()">
                            <canvas></canvas>
                        </div>
                        <ul class="rk-an-sidestats">
                            <li>
                                <span class="rk-an-sidestats__icon"><x-heroicon-o-plus-circle class="h-6 w-6" /></span>
                                <div><b>{{ $createdTotal }}</b><span>{{ __('Created') }}</span></div>
                            </li>
                            <li>
                                <span class="rk-an-sidestats__icon"><x-heroicon-o-check-circle class="h-6 w-6" /></span>
                                <div><b>{{ $resolvedTotal }}</b><span>{{ __('Resolved') }}</span></div>
                            </li>
                            <li>
                                <span class="rk-an-sidestats__icon"><x-heroicon-o-switch-vertical class="h-6 w-6" /></span>
                                <div><b>{{ $net > 0 ? '+' : '' }}{{ $net }}</b><span>{{ __('Backlog change') }}</span></div>
                            </li>
                        </ul>
                    </div>
                </x-filament::card>

                {{-- time to resolve --}}
                <x-filament::card class="rk-an-card rk-an-hero rk-span-4">
                    <h3 class="rk-an-title">{{ __('Time to resolve') }}</h3>
                    <p class="rk-an-sub">{{ __('From creation to done, tickets finished in the last 90 days') }}</p>

                    @if ($cycle['count'])
                        <div class="rk-an-hero__big">
                            <b>{{ $cycle['avg_days'] }}</b>
                            <span>{{ __('days on average') }}</span>
                        </div>
                        <div class="rk-an-hero__chips">
                            <span><b>{{ $cycle['median_days'] }}</b> {{ __('days median') }}</span>
                            <span><b>{{ $cycle['count'] }}</b> {{ __('tickets finished') }}</span>
                        </div>
                        @foreach ($cycle['by_type'] as $row)
                            <div class="rk-an-hero__row">
                                <span>{{ $row['name'] }}</span>
                                <span>{{ $row['avg_days'] }} {{ __('days') }}</span>
                            </div>
                        @endforeach
                    @else
                        <p class="rk-an-hero__empty">{{ __('No tickets finished in the last 90 days.') }}</p>
                    @endif
                </x-filament::card>

                {{-- type doughnut + key list (like a "traffic distribution" card) --}}
                <x-filament::card class="rk-an-card rk-an-donutcard-wrap rk-span-4">
                    <h3 class="rk-an-title">{{ __('Open tickets by type') }}</h3>
                    <p class="rk-an-sub">{{ __('Bugs, tasks and features still open') }}</p>

                    @if (count($in['type']))
                        <div class="rk-an-donutcard">
                            <div class="rk-an-chart rk-an-chart--donut" wire:ignore wire:key="type-{{ $this->projectId }}"
                                 x-data x-init="const cfg = @js($typeCfg); const run = () => window.rkCharts ? window.rkCharts.doughnut($el.querySelector('canvas'), cfg) : setTimeout(run, 50); run()">
                                <canvas></canvas>
                            </div>
                            <ul class="rk-an-bigkeys">
                                @foreach ($in['type'] as $i => $row)
                                    <li>
                                        <i style="border-color: {{ $blueAt($i) }}"></i>
                                        <div><b>{{ $row['count'] }}</b><span>{{ $row['name'] }}</span></div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <p class="rk-an-empty">{{ __('No open tickets.') }}</p>
                    @endif
                </x-filament::card>

                {{-- workload --}}
                <x-filament::card class="rk-an-card rk-span-4">
                    <h3 class="rk-an-title">{{ __('Workload by member') }}</h3>
                    <p class="rk-an-sub">{{ __('Open tickets per assignee, and tickets finished in the last 30 days') }}</p>

                    <div class="rk-an-scroll">
                    @forelse ($in['workload'] as $row)
                        <div class="rk-an-person">
                            <span class="rk-an-person__avatar" style="background: #3b82d6">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($row['name'], 0, 1)) }}</span>
                            <div class="rk-an-person__body">
                                <div class="rk-an-row__head">
                                    <span>{{ $row['name'] }}</span>
                                    <span class="rk-an-muted">{{ $row['open'] }} {{ __('open') }} · {{ $row['done_30d'] }} {{ __('finished') }}</span>
                                </div>
                                <div class="rk-an-stack">
                                    <div style="width: {{ $row['open'] / $maxLoad * 100 }}%; background: #3b82d6"></div>
                                    <div style="width: {{ $row['done_30d'] / $maxLoad * 100 }}%; background: #3b82d6; opacity: .35"></div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="rk-an-empty">{{ __('No open tickets.') }}</p>
                    @endforelse
                    </div>
                </x-filament::card>

                {{-- priority: segmented bar --}}
                <x-filament::card class="rk-an-card rk-span-4">
                    <h3 class="rk-an-title">{{ __('Open tickets by priority') }}</h3>
                    <p class="rk-an-sub">{{ __('Where the unfinished work sits by urgency') }}</p>

                    @if (count($in['priority']))
                        <div class="rk-an-segbar">
                            @foreach ($in['priority'] as $i => $row)
                                <div style="width: {{ $row['count'] / $totalP * 100 }}%; background: {{ $blueAt($i) }}" title="{{ $row['name'] }}"></div>
                            @endforeach
                        </div>
                        <ul class="rk-an-keylist">
                            @foreach ($in['priority'] as $i => $row)
                                <li>
                                    <i style="background: {{ $blueAt($i) }}"></i>
                                    <span>{{ $row['name'] }}</span>
                                    <b>{{ $row['count'] }}</b>
                                    <em>{{ round($row['count'] / $totalP * 100) }}%</em>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="rk-an-empty">{{ __('No open tickets.') }}</p>
                    @endif
                </x-filament::card>

                <x-filament::card class="rk-an-card rk-an-epiccard rk-span-4">
                    <h3 class="rk-an-title">{{ __('Epic progress') }}</h3>
                    <p class="rk-an-sub">{{ __('Completed vs total tickets per epic') }}</p>

                    @if (count($in['epics']))
                        <div class="rk-an-rings">
                            @foreach ($in['epics'] as $row)
                                <div class="rk-an-ring" title="{{ $row['done'] }}/{{ $row['total'] }}">
                                    <svg viewBox="0 0 36 36" role="img">
                                        <circle cx="18" cy="18" r="15.9155" fill="transparent" stroke="#eef1f5" stroke-width="3.5" />
                                        <circle cx="18" cy="18" r="15.9155" fill="transparent" stroke="#3b82d6"
                                                stroke-width="3.5" stroke-linecap="round"
                                                stroke-dasharray="{{ $row['pct'] }} {{ 100 - $row['pct'] }}" stroke-dashoffset="25" />
                                        <text x="18" y="20.5" text-anchor="middle" font-size="7.5" font-weight="700" fill="#111827">{{ $row['pct'] }}%</text>
                                    </svg>
                                    <span>{{ $row['name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="rk-an-empty">{{ __('No epics with tickets.') }}</p>
                    @endif
                </x-filament::card>

                <x-filament::card class="rk-an-card rk-span-8">
                    <h3 class="rk-an-title">{{ __('Stale tickets') }}</h3>
                    <p class="rk-an-sub">{{ __('Open tickets with no update for 14 days or more') }}</p>

                    <div class="rk-an-twocol">
                    @forelse (array_slice($in['stale'], 0, 6) as $row)
                        <a href="{{ route('filament.resources.tickets.view', $row['id']) }}" class="rk-an-overdue">
                            <span class="rk-an-overdue__code">{{ $row['code'] }}</span>
                            <span class="rk-an-overdue__name">{{ $row['name'] }}</span>
                            <span class="rk-an-muted">{{ $row['assignee'] ?? __('Unassigned') }}</span>
                            <span class="rk-an-late">{{ $row['days'] }} {{ __('days') }}</span>
                        </a>
                    @empty
                        <p class="rk-an-empty">{{ __('No stale tickets. Everything is moving.') }}</p>
                    @endforelse
                    </div>

                    @if (count($in['stale']) > 6)
                        <p class="rk-an-muted rk-an-more">{{ __('+ :count more', ['count' => count($in['stale']) - 6]) }}</p>
                    @endif
                </x-filament::card>

                {{-- tickets per sprint --}}
                @if ($hasSprints)
                    <x-filament::card class="rk-an-card rk-span-4">
                        <h3 class="rk-an-title">{{ __('Tickets per sprint') }}</h3>
                        <p class="rk-an-sub">{{ __('Finished vs total tickets in each sprint') }}</p>

                        @php $maxS = max(1, collect($in['sprints'])->max('total')); @endphp
                        <div class="rk-an-columns">
                            @foreach ($in['sprints'] as $row)
                                <div class="rk-an-column">
                                    <div class="rk-an-column__bar">
                                        <div class="rk-an-column__total" style="height: {{ $row['total'] / $maxS * 100 }}%">
                                            <div class="rk-an-column__done" style="height: {{ $row['total'] ? $row['done'] / $row['total'] * 100 : 0 }}%"></div>
                                        </div>
                                    </div>
                                    <b>{{ $row['done'] }}/{{ $row['total'] }}</b>
                                    <span>{{ \Illuminate\Support\Str::limit($row['name'], 10, '') }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::card>
                @endif

                {{-- logged hours --}}
                <x-filament::card class="rk-an-card {{ $hasSprints ? 'rk-span-8' : 'rk-span-12' }}">
                    <h3 class="rk-an-title">{{ __('Resource utilization') }}</h3>
                    <p class="rk-an-sub">{{ __('Hours logged in the last 30 days') }}</p>

                    @if ($utilization->isNotEmpty())
                        <div class="rk-an-table"><table>
                            <thead>
                                <tr>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Hours') }}</th>
                                    <th class="w-1/2">{{ __('Utilization') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($utilization as $row)
                                    <tr>
                                        <td>{{ $row['user_name'] ?? '—' }}</td>
                                        <td class="tabular-nums">{{ number_format($row['hours_logged'], 1) }}</td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="rk-an-bar">
                                                    <div style="width: {{ min(100, $row['utilization_pct']) }}%"></div>
                                                </div>
                                                <span class="tabular-nums text-gray-500">{{ number_format($row['utilization_pct'], 0) }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @else
                        <p class="rk-an-empty">{{ __('No time logged in the last 30 days.') }}</p>
                    @endif
                </x-filament::card>
            </div>
        @endif
    @endif
</x-filament::page>

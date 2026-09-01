<x-filament::page>
    @php
        $projects = $this->accessibleProjects();
        $sprints = $this->sprintOptions();
        $forecast = $this->forecast();
        $velocity = $this->velocity();
        $burndown = $this->burndown();
        $utilization = $this->utilization();
    @endphp

    {{-- ---------------------------------------------------------- selectors --}}
    <div class="flex flex-wrap items-end gap-4">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-600 dark:text-gray-300">{{ __('Project') }}</span>
            <select wire:model="projectId"
                    class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                @forelse ($projects as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @empty
                    <option value="">{{ __('No projects') }}</option>
                @endforelse
            </select>
        </label>

        @if ($sprints->isNotEmpty())
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-gray-600 dark:text-gray-300">{{ __('Sprint (burn-down)') }}</span>
                <select wire:model="sprintId"
                        class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach ($sprints as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    @if (! $this->currentProject())
        <x-filament::card>
            <p class="text-gray-500">{{ __('Select a project to view its analytics.') }}</p>
        </x-filament::card>
    @else
        {{-- ------------------------------------------------ forecast cards --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            @php
                $cards = [
                    [__('Outstanding work'), number_format($forecast['remaining_points'], 1) . ' ' . __('pts')],
                    [__('Avg. velocity'), number_format($forecast['avg_velocity'], 1) . ' ' . __('pts/sprint')],
                    [__('Sprints remaining'), $forecast['sprints_remaining'] ?? '—'],
                    [__('Forecast completion'), $forecast['forecast_date'] ?? __('Not enough data')],
                ];
            @endphp
            @foreach ($cards as [$label, $value])
                <x-filament::card>
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-bold tracking-tight">{{ $value }}</div>
                </x-filament::card>
            @endforeach
        </div>

        @unless ($forecast['confident'])
            <p class="text-sm text-warning-600">
                {{ __('Forecast needs at least one closed sprint with completed work.') }}
            </p>
        @endunless

        {{-- ------------------------------------------------- velocity chart --}}
        <x-filament::card>
            <h3 class="text-lg font-semibold">{{ __('Team velocity') }}</h3>
            <p class="mb-3 text-sm text-gray-500">{{ __('Completed vs committed points per sprint') }}</p>

            @if (count($velocity))
                @php
                    $maxV = max(1, collect($velocity)->max('committed_points'));
                    $barW = 46; $gap = 24; $chartH = 180;
                    $chartW = max(count($velocity) * ($barW + $gap) + $gap, 120);
                @endphp
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 30 }}" class="h-56 w-full" preserveAspectRatio="xMinYMid meet">
                        @foreach ($velocity as $i => $row)
                            @php
                                $x = $gap + $i * ($barW + $gap);
                                $committedH = ($row['committed_points'] / $maxV) * $chartH;
                                $completedH = ($row['completed_points'] / $maxV) * $chartH;
                            @endphp
                            <rect x="{{ $x }}" y="{{ $chartH - $committedH }}" width="{{ $barW }}" height="{{ $committedH }}"
                                  rx="3" fill="#e5e7eb" />
                            <rect x="{{ $x }}" y="{{ $chartH - $completedH }}" width="{{ $barW }}" height="{{ $completedH }}"
                                  rx="3" fill="#3b82f6" />
                            <text x="{{ $x + $barW / 2 }}" y="{{ $chartH + 18 }}" text-anchor="middle"
                                  font-size="11" fill="#9ca3af">{{ \Illuminate\Support\Str::limit($row['sprint_name'], 8, '') }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded" style="background:#3b82f6"></span>{{ __('Completed') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded" style="background:#e5e7eb"></span>{{ __('Committed') }}</span>
                </div>
            @else
                <p class="text-gray-500">{{ __('No sprints yet.') }}</p>
            @endif
        </x-filament::card>

        {{-- ------------------------------------------------- burndown chart --}}
        <x-filament::card>
            <h3 class="text-lg font-semibold">{{ __('Sprint burn-down') }}</h3>
            <p class="mb-3 text-sm text-gray-500">{{ __('Remaining work vs the ideal burn') }}</p>

            @if (count($burndown['labels']) > 1)
                @php
                    $n = count($burndown['labels']);
                    $total = max(0.0001, $burndown['total']);
                    $w = 640; $h = 200; $pad = 12;
                    $toPoints = function (array $series) use ($n, $total, $w, $h, $pad) {
                        $out = [];
                        foreach ($series as $i => $v) {
                            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
                            $y = $pad + (1 - ($v / $total)) * ($h - 2 * $pad);
                            $out[] = round($x, 1) . ',' . round($y, 1);
                        }
                        return implode(' ', $out);
                    };
                @endphp
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-52 w-full" preserveAspectRatio="xMinYMid meet">
                        <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#e5e7eb" />
                        <polyline points="{{ $toPoints($burndown['ideal']) }}" fill="none" stroke="#9ca3af"
                                  stroke-width="1.5" stroke-dasharray="5 4" />
                        <polyline points="{{ $toPoints($burndown['remaining']) }}" fill="none" stroke="#3b82f6" stroke-width="2.5" />
                    </svg>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background:#3b82f6"></span>{{ __('Remaining') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background:#9ca3af"></span>{{ __('Ideal') }}</span>
                    <span class="ml-auto">{{ __('Total') }}: {{ number_format($burndown['total'], 1) }} {{ __('pts') }}</span>
                </div>
            @else
                <p class="text-gray-500">{{ __('Select a sprint with a start and end date.') }}</p>
            @endif
        </x-filament::card>

        {{-- --------------------------------------------- utilization table --}}
        <x-filament::card>
            <h3 class="text-lg font-semibold">{{ __('Resource utilization') }}</h3>
            <p class="mb-3 text-sm text-gray-500">{{ __('Hours logged in the last 30 days') }}</p>

            @if ($utilization->isNotEmpty())
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-1">{{ __('User') }}</th>
                            <th class="py-1">{{ __('Hours') }}</th>
                            <th class="py-1 w-1/2">{{ __('Utilization') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($utilization as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="py-2">{{ $row['user_name'] ?? '—' }}</td>
                                <td class="py-2 tabular-nums">{{ number_format($row['hours_logged'], 1) }}</td>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 flex-1 rounded bg-gray-100 dark:bg-gray-700">
                                            <div class="h-2 rounded bg-primary-500"
                                                 style="width: {{ min(100, $row['utilization_pct']) }}%"></div>
                                        </div>
                                        <span class="tabular-nums text-gray-500">{{ number_format($row['utilization_pct'], 0) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-gray-500">{{ __('No time logged in the last 30 days.') }}</p>
            @endif
        </x-filament::card>
    @endif
</x-filament::page>

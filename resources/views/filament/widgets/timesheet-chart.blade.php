{{-- Copy of filament::widgets.chart-widget with the native <select> filter swapped for the app's pill dropdown (.rk-dd). --}}
@php
    $heading = $this->getHeading();
    $filters = $this->getFilters();
@endphp

<x-filament::widget class="filament-widgets-chart-widget">
    <x-filament::card>
        @if ($heading || $filters)
            <div class="flex items-center justify-between gap-8">
                @if ($heading)
                    <x-filament::card.heading>
                        {{ $heading }}
                    </x-filament::card.heading>
                @endif

                @if ($filters)
                    <div class="rk-dd rk-dd--sm"
                         x-data="{ open: false, value: $wire.entangle('filter'), options: @js($filters) }"
                         x-on:keydown.escape="open = false"
                         x-on:click.outside="open = false"
                         wire:loading.class="animate-pulse">
                        <button type="button" class="rk-dd__btn" x-on:click="open = ! open"
                                aria-haspopup="listbox" x-bind:aria-expanded="open">
                            <span class="rk-dd__label" x-text="options[value] ?? value"></span>
                            <x-heroicon-o-chevron-down class="rk-dd__chevron" x-bind:class="{ 'rotate-180': open }" />
                        </button>
                        <ul class="rk-dd__list" role="listbox" x-show="open" x-transition.opacity x-cloak>
                            @foreach ($filters as $value => $label)
                                <li role="option">
                                    <button type="button" class="rk-dd__item"
                                            x-bind:class="{ 'is-selected': value == @js((string) $value) }"
                                            x-on:click="value = @js((string) $value); open = false">{{ $label }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <x-filament::hr />
        @endif

        <div
            {!! ($pollingInterval = $this->getPollingInterval()) ? "wire:poll.{$pollingInterval}=\"updateChartData\"" : '' !!}
        >
            <canvas
                x-data="{
                    chart: null,

                    init: function () {
                        let chart = this.initChart()

                        $wire.on('updateChartData', async ({ data }) => {
                            chart.data = this.applyColorToData(data)
                            chart.update('resize')
                        })

                        $wire.on('filterChartData', async ({ data }) => {
                            chart.destroy()
                            chart = this.initChart(data)
                        })
                    },

                    initChart: function (data = null) {
                        data = data ?? {{ json_encode($this->getCachedData()) }}

                        return (this.chart = new Chart($el, {
                            type: '{{ $this->getType() }}',
                            data: this.applyColorToData(data),
                            options: {{ json_encode($this->getOptions()) }} ?? {},
                        }))
                    },

                    applyColorToData: function (data) {
                        data.datasets.forEach((dataset, datasetIndex) => {
                            if (! dataset.backgroundColor) {
                                data.datasets[datasetIndex].backgroundColor = getComputedStyle(
                                    $refs.backgroundColorElement,
                                ).color
                            }

                            if (! dataset.borderColor) {
                                data.datasets[datasetIndex].borderColor = getComputedStyle(
                                    $refs.borderColorElement,
                                ).color
                            }
                        })

                        return data
                    },
                }"
                wire:ignore
                @if ($maxHeight = $this->getMaxHeight())
                    style="max-height: {{ $maxHeight }}"
                @endif
            >
                <span
                    x-ref="backgroundColorElement"
                    @class([
                        'text-gray-50',
                        'dark:text-gray-300' => config('filament.dark_mode'),
                    ])
                ></span>

                <span
                    x-ref="borderColorElement"
                    @class([
                        'text-gray-500',
                        'dark:text-gray-200' => config('filament.dark_mode'),
                    ])
                ></span>
            </canvas>
        </div>
    </x-filament::card>
</x-filament::widget>

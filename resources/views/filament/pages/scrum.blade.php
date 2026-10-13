<x-filament::page class="rk-board-page">

    @if($project->currentSprint)

        @php
            // Build the board once per render: a single ticket query grouped by
            // status, instead of re-querying inside the column loop.
            $statuses = $this->getStatuses();
            $recordsByStatus = $this->getRecords()->groupBy('status');
        @endphp

        <div class="rk-board-card">
        <div class="mx-auto w-full" wire:ignore>
            <details class="rk-board-filter">
                <summary class="rk-board-filter__btn">
                    {{ __('Filters') }}
                </summary>
                <div class="rk-board-filter__body">
                    <form>
                        {{ $this->form }}
                    </form>
                </div>
            </details>
        </div>

        <div class="kanban-container">

            @foreach($statuses as $status)
                @include('partials.kanban.status', ['records' => $recordsByStatus->get($status['id'], collect())])
            @endforeach

        </div>
        </div>

        @push('scripts')
            <script src="{{ asset('js/Sortable.js') }}"></script>
            <script>

                (() => {
                    let record;
                    @foreach($statuses as $status)
                        record = document.querySelector('#status-records-{{ $status['id'] }}');

                    Sortable.create(record, {
                        group: {
                            name: 'status-{{ $status['id'] }}',
                            pull: true,
                            put: true
                        },
                        // The whole card is the drag surface (not just a corner
                        // button). Only ticket cards drag (not the "create"
                        // link); a short delay on touch keeps scrolling usable,
                        // and a small tolerance keeps plain clicks (open ticket) working.
                        draggable: '.kanban-record',
                        delay: 120,
                        delayOnTouchOnly: true,
                        touchStartThreshold: 5,
                        fallbackTolerance: 4,
                        ghostClass: 'kanban-ghost',
                        chosenClass: 'kanban-chosen',
                        animation: 150,
                        onEnd: function (evt) {
                            Livewire.emit('recordUpdated',
                                +evt.clone.dataset.id, // id
                                +evt.newIndex, // newIndex
                                +evt.to.dataset.status, // newStatus
                            );
                        },
                    })
                    @endforeach
                })();
            </script>
        @endpush
    @else
        <div class="rk-board-card">
            <span class="text-lg font-semibold text-gray-900">
                {{ __('No active sprint for this project!') }}
            </span>
            @if(auth()->user()->can('update', $project))
                <span class="text-gray-500 text-sm">
                    {{ __("Click the below button to manage project's sprints") }}
                </span>
                <a href="{{ route('filament.resources.projects.view', $project) }}"
                   class="rk-board-link mt-3">
                    {{ __('Manage sprints') }}
                </a>
            @else
                <span class="text-gray-500 text-sm">
                    {{ __("If you think a sprint should be started, please contact an administrator") }}
                </span>
            @endif
        </div>
    @endif

</x-filament::page>

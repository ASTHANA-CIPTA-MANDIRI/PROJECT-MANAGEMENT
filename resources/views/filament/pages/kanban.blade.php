<x-filament::page>

    @php
        // Build the board once per render: a single ticket query grouped by
        // status, instead of re-querying inside the column loop.
        $statuses = $this->getStatuses();
        $recordsByStatus = $this->getRecords()->groupBy('status');
    @endphp

    <div class="mx-auto w-full" wire:ignore>
        <details class="w-full bg-white open:bg-gray-200 duration-300">
            <summary
                class="relative w-full bg-inherit px-5 py-3 text-base cursor-pointer text-gray-500">
                {{ __('Filters') }}
            </summary>
            <div class="bg-white px-5 py-3">
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

    @push('scripts')
        <script src="{{ asset('js/Sortable.js') }}"></script>
        <script>

            (() => {
                // Livewire morphs .kanban-container on every re-render (a
                // drag, a filter, or a broadcast event like
                // ticket.status.changed/ticket.comment.posted - see
                // KanbanScrumHelper::getListeners()), which can replace or
                // reorder the column nodes. This script tag itself only runs
                // once, so Sortable has to be (re)initialized after every
                // Livewire update, not just on first load - otherwise drag-
                // and-drop quietly stops working the moment the board
                // refreshes. Read the columns straight from the DOM each time
                // rather than baking the status list into this once-rendered
                // script, so a re-render is always reflected.
                function initKanbanSortable() {
                    document.querySelectorAll('.status-container[data-status]').forEach(function (el) {
                        const statusId = el.dataset.status;

                        // Re-running Sortable.create() on the same element
                        // would stack a second set of drag listeners on top
                        // of the first; destroy whatever instance is already
                        // attached before creating a new one.
                        const existing = Sortable.get(el);
                        if (existing) {
                            existing.destroy();
                        }

                        Sortable.create(el, {
                            group: {
                                name: 'status-' + statusId,
                                pull: true,
                                put: true
                            },
                            handle: '.handle',
                            animation: 100,
                            onEnd: function (evt) {
                                Livewire.emit('recordUpdated',
                                    +evt.clone.dataset.id, // id
                                    +evt.newIndex, // newIndex
                                    +evt.to.dataset.status, // newStatus
                                );
                            },
                        });
                    });
                }

                // Livewire v2 API (see resources/views/vendor/filament-breezy/login.blade.php
                // for the same pattern): livewire:load + message.processed,
                // not the v3 lifecycle hooks.
                document.addEventListener('livewire:load', function () {
                    if (window.Livewire) {
                        Livewire.hook('message.processed', function () { setTimeout(initKanbanSortable, 50); });
                    }
                });
                document.addEventListener('DOMContentLoaded', function () { setTimeout(initKanbanSortable, 300); });
            })();
        </script>
    @endpush

</x-filament::page>

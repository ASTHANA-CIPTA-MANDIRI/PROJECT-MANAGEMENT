<x-filament::page>

    @php($session = $this->session())

    @if ($session)
        @php($overview = $this->overview())

        <div class="w-full flex flex-col gap-8">

            <div class="bg-warning-500 border border-warning-600 text-white py-3 px-4 text-sm rounded-lg flex flex-col gap-1">
                <span class="font-medium">{{ $session->organization->name }}</span>
                <span class="font-normal">{{ __('Alasan') }}: {{ $session->reason }}</span>
                <span class="font-normal">
                    {{ __('Started at') }}: {{ $session->started_at->diffForHumans() }}
                    &middot;
                    {{ __('Expires at') }}: {{ $session->expires_at->diffForHumans() }}
                </span>
                <span class="font-normal">
                    {{ __('Support level') }}:
                    {{
                        $session->isFullAccess()
                            ? __('Support level: Full Access')
                            : ($session->isSupportAction() ? __('Support level: Support Action') : __('Support level: Read Only'))
                    }}
                </span>
            </div>

            {{-- Organization overview --}}
            @if ($overview)
                <div class="flex flex-col gap-2">
                    <h3 class="text-base font-medium">{{ __('Organization overview') }}</h3>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Trial status') }}</span>
                            <span class="text-sm font-medium">{{ $overview['trial_label'] }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Created at') }}</span>
                            <span class="text-sm font-medium">{{ $overview['created_at']?->toDateTimeString() }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Projects') }}</span>
                            <span class="text-sm font-medium">{{ $overview['projects_count'] }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Members') }}</span>
                            <span class="text-sm font-medium">{{ $overview['members_count'] }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Tickets') }}</span>
                            <span class="text-sm font-medium">{{ $overview['tickets_count'] }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Active sprints') }}</span>
                            <span class="text-sm font-medium">{{ $overview['active_sprints_count'] }}</span>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex flex-col gap-1">
                            <span class="text-xs text-gray-500">{{ __('Overdue tickets') }}</span>
                            <span class="text-sm font-medium">{{ $overview['overdue_tickets_count'] }}</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Projects --}}
            <div class="flex flex-col gap-2">
                <h3 class="text-base font-medium">{{ __('Projects') }}</h3>

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Organization') }}</th>
                            <th class="py-2 pr-4">{{ __('Ticket prefix') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Tickets') }}</th>
                            <th class="py-2 pr-4">{{ __('Open tickets') }}</th>
                            <th class="py-2 pr-4">{{ __('Overdue tickets') }}</th>
                            <th class="py-2 pr-4">{{ __('Active sprint') }}</th>
                            <th class="py-2 pr-4">{{ __('Created at') }}</th>
                            @if ($session->isFullAccess())
                                <th class="py-2 pr-4">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->projects() as $project)
                            <tr class="border-b border-gray-100 dark:border-gray-800 @if ($project->trashed()) opacity-50 @endif">
                                <td class="py-2 pr-4">{{ $project->name }}</td>
                                <td class="py-2 pr-4">{{ $project->ticket_prefix }}</td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as Ticket Status above. --}}
                                        <select
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this project\'s status?')))) {
                                                    $wire.changeProjectStatus({{ $project->id }}, parseInt($event.target.value))
                                                } else {
                                                    $event.target.value = '{{ $project->status_id }}'
                                                }
                                            "
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        >
                                            @foreach ($this->projectStatusOptions() as $status)
                                                <option value="{{ $status->id }}" @selected($status->id === $project->status_id)>
                                                    {{ $status->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @elseif ($project->status)
                                        @include('components.color-badge', ['color' => $project->status->color, 'label' => $project->status->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $project->tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->open_tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->overdue_tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->sprints->first()?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $project->created_at?->toDateTimeString() }}</td>
                                @if ($session->isFullAccess())
                                    <td class="py-2 pr-4">
                                        {{-- Phase 13: the third destructive Full Access control, same
                                             confirm()-gated button pattern as Delete/Restore Ticket/Sprint
                                             (Phase 11/12) — but a Project cascades to every Ticket, Sprint
                                             and Epic beneath it, so both confirm() messages state the real,
                                             verified impact counts (tickets_count/sprints_count/epics_count
                                             already eager-loaded on $project for the delete case;
                                             projectRestoreImpact() re-derives the within-cascade-window
                                             counts for the restore case) rather than a generic warning. --}}
                                        @if (! $project->trashed())
                                            @php($projectDeleteConfirm = __('Delete project :project from :organization? This will also soft-delete :tickets ticket(s), :sprints sprint(s), and :epics epic(s) under it. Everything can be restored later.', ['project' => $project->name, 'organization' => $session->organization->name, 'tickets' => $project->tickets_count, 'sprints' => $project->sprints_count, 'epics' => $project->epics_count]))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js($projectDeleteConfirm))) {
                                                        $wire.deleteProject({{ $project->id }})
                                                    }
                                                "
                                                class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        @else
                                            @php($projectRestoreCounts = $this->projectRestoreImpact($project->id))
                                            @php($projectRestoreConfirm = __('Restore project :project in :organization? This will also restore :tickets ticket(s), :sprints sprint(s), and :epics epic(s) that were deleted along with it.', ['project' => $project->name, 'organization' => $session->organization->name, 'tickets' => $projectRestoreCounts['tickets'], 'sprints' => $projectRestoreCounts['sprints'], 'epics' => $projectRestoreCounts['epics']]))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js($projectRestoreConfirm))) {
                                                        $wire.restoreProject({{ $project->id }})
                                                    }
                                                "
                                                class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                            >
                                                {{ __('Restore') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $session->isFullAccess() ? 9 : 8 }}" class="py-4 text-gray-500">{{ __('No projects yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Tickets --}}
            @php($ticketsForBulk = $this->tickets())
            @php($bulkDeleteTicketConfirmTemplate = __('Delete %COUNT% selected ticket(s) from :organization? Items: %ITEMS%. Tickets will be soft-deleted (moved to trash) and can be restored later.', ['organization' => $session->organization->name]))
            @php($bulkRestoreTicketConfirmTemplate = __('Restore %COUNT% selected ticket(s) in :organization? Items: %ITEMS%. Tickets will be moved out of trash and become visible again.', ['organization' => $session->organization->name]))
            <div class="flex flex-col gap-2" x-data="{ selectedTicketIds: [] }">
                <h3 class="text-base font-medium">{{ __('Tickets') }}</h3>

                @if ($session->isFullAccess())
                    {{-- Phase 14: bulk delete/restore. Both buttons stay
                         visible regardless of selection (unlike a
                         disabled-until-selected pattern) — a click with
                         nothing selected simply confirm()s an empty batch
                         and bulkDeleteTicket()/bulkRestoreTicket() reject it
                         server-side (resolveBulkTickets()'s own
                         abort_unless(isNotEmpty())) the same fail-closed way
                         every other invalid input on this page is rejected,
                         so there is no client-only gate whose bypass would
                         matter. --}}
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500" x-show="selectedTicketIds.length > 0" x-text="selectedTicketIds.length + ' {{ __('selected') }}'"></span>
                        <button
                            type="button"
                            x-on:click="
                                let items = Array.from($root.querySelectorAll('[data-ticket-checkbox]:checked')).map((cb) => cb.dataset.ticketName + (cb.dataset.ticketState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkDeleteTicketConfirmTemplate).replace('%COUNT%', selectedTicketIds.length).replace('%ITEMS%', items.join(', '));
                                if (confirm(message)) {
                                    $wire.bulkDeleteTicket(selectedTicketIds);
                                    selectedTicketIds = [];
                                }
                            "
                            class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                        >
                            {{ __('Bulk Delete') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="
                                let items = Array.from($root.querySelectorAll('[data-ticket-checkbox]:checked')).map((cb) => cb.dataset.ticketName + (cb.dataset.ticketState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkRestoreTicketConfirmTemplate).replace('%COUNT%', selectedTicketIds.length).replace('%ITEMS%', items.join(', '));
                                if (confirm(message)) {
                                    $wire.bulkRestoreTicket(selectedTicketIds);
                                    selectedTicketIds = [];
                                }
                            "
                            class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400"
                        >
                            {{ __('Bulk Restore') }}
                        </button>
                    </div>
                @endif

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            @if ($session->isFullAccess())
                                <th class="py-2 pr-4">
                                    <input
                                        type="checkbox"
                                        x-on:change="selectedTicketIds = $event.target.checked ? @js($ticketsForBulk->pluck('id')->all()) : []"
                                    />
                                    <span class="sr-only">{{ __('Select all') }}</span>
                                </th>
                            @endif
                            <th class="py-2 pr-4">{{ __('Ticket name') }}</th>
                            <th class="py-2 pr-4">{{ __('Projects') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Priority') }}</th>
                            <th class="py-2 pr-4">{{ __('Labels') }}</th>
                            <th class="py-2 pr-4">{{ __('Responsible') }}</th>
                            <th class="py-2 pr-4">{{ __('Due date') }}</th>
                            <th class="py-2 pr-4">{{ __('Created at') }}</th>
                            @if ($session->isFullAccess())
                                <th class="py-2 pr-4">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ticketsForBulk as $ticket)
                            <tr class="border-b border-gray-100 dark:border-gray-800 @if ($ticket->trashed()) opacity-50 @endif">
                                @if ($session->isFullAccess())
                                    <td class="py-2 pr-4">
                                        <input
                                            type="checkbox"
                                            data-ticket-checkbox
                                            data-ticket-name="{{ $ticket->name }}"
                                            data-ticket-state="{{ $ticket->trashed() ? 'trashed' : 'active' }}"
                                            value="{{ $ticket->id }}"
                                            x-model.number="selectedTicketIds"
                                        />
                                    </td>
                                @endif
                                <td class="py-2 pr-4">
                                    {{ $ticket->code }} —
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as the other Support Action
                                             controls. @js() (not raw Blade interpolation) is required
                                             for the JS-context reset value below — unlike status/
                                             priority/responsible/due-date, a ticket's name is free text
                                             that can legitimately contain a literal apostrophe (e.g.
                                             "Fix the user's login bug"), which would otherwise break
                                             out of the JS string. --}}
                                        <input
                                            type="text"
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s title?')))) {
                                                    $wire.changeTicketTitle({{ $ticket->id }}, $event.target.value)
                                                } else {
                                                    $event.target.value = @js($ticket->name)
                                                }
                                            "
                                            value="{{ $ticket->name }}"
                                            maxlength="255"
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        />
                                    @else
                                        {{ $ticket->name }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $ticket->project?->name }}</td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction() || $session->isFullAccess())
                                        {{-- Support Action and Full Access both reach changeTicketStatus()
                                             (Phase 9: change_ticket_status is Full Access's pilot
                                             capability) — a confirm() gate sits in front of the Livewire
                                             call itself (not just a UI affordance); canceling resets the
                                             <select> back to the ticket's current status so the control
                                             never shows a value that was not actually saved. --}}
                                        <select
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s status?')))) {
                                                    $wire.changeTicketStatus({{ $ticket->id }}, parseInt($event.target.value))
                                                } else {
                                                    $event.target.value = '{{ $ticket->status_id }}'
                                                }
                                            "
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        >
                                            @foreach ($this->ticketStatusOptions($ticket) as $status)
                                                <option value="{{ $status->id }}" @selected($status->id === $ticket->status_id)>
                                                    {{ $status->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @elseif ($ticket->status)
                                        @include('components.color-badge', ['color' => $ticket->status->color, 'label' => $ticket->status->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as the Status select above:
                                             canceling resets the <select> back to the ticket's current
                                             priority so it never shows a value that was not saved. --}}
                                        <select
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s priority?')))) {
                                                    $wire.changeTicketPriority({{ $ticket->id }}, parseInt($event.target.value))
                                                } else {
                                                    $event.target.value = '{{ $ticket->priority_id }}'
                                                }
                                            "
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        >
                                            @foreach ($this->priorityOptions() as $priority)
                                                <option value="{{ $priority->id }}" @selected($priority->id === $ticket->priority_id)>
                                                    {{ $priority->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @elseif ($ticket->priority)
                                        @include('components.color-badge', ['color' => $ticket->priority->color, 'label' => $ticket->priority->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as Status/Priority above, but for
                                             a multi-select: the selected ids are read off the <select>
                                             itself at change time (never trusted further than any other
                                             client input), and a cancel restores the option list back to
                                             $ticket's own current labels rather than leaving the browser's
                                             now-changed selection on screen unsent. --}}
                                        <select
                                            multiple
                                            x-data
                                            x-on:change="
                                                let selected = Array.from($event.target.selectedOptions).map((option) => parseInt(option.value));
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s labels?')))) {
                                                    $wire.changeTicketLabels({{ $ticket->id }}, selected)
                                                } else {
                                                    let current = @js($ticket->labels->pluck('id')->all());
                                                    Array.from($event.target.options).forEach((option) => {
                                                        option.selected = current.includes(parseInt(option.value));
                                                    });
                                                }
                                            "
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        >
                                            @foreach ($this->labelOptions() as $label)
                                                <option value="{{ $label->id }}" @selected($ticket->labels->pluck('id')->contains($label->id))>
                                                    {{ $label->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($ticket->labels as $label)
                                                @include('components.color-badge', ['color' => $label->color, 'label' => $label->name])
                                            @empty
                                                &mdash;
                                            @endforelse
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as Status/Priority above. An
                                             empty <option value=""> is the "clear assignment" choice —
                                             the confirm handler passes null (not parseInt('')) for it. --}}
                                        <select
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s responsible?')))) {
                                                    let value = $event.target.value;
                                                    $wire.changeTicketResponsible({{ $ticket->id }}, value === '' ? null : parseInt(value))
                                                } else {
                                                    $event.target.value = '{{ $ticket->responsible_id ?? '' }}'
                                                }
                                            "
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        >
                                            <option value="" @selected($ticket->responsible_id === null)>{{ __('Unassigned') }}</option>
                                            @foreach ($this->responsibleOptions($ticket->id) as $user)
                                                <option value="{{ $user->id }}" @selected($user->id === $ticket->responsible_id)>
                                                    {{ $user->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ $ticket->responsible?->name ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as Status/Priority/Responsible
                                             above. An empty string means "clear the due date" — passed
                                             through as null, not an empty-string value. --}}
                                        <input
                                            type="date"
                                            x-data
                                            x-on:change="
                                                if (confirm(@js(__('Are you sure you want to change this ticket\'s due date?')))) {
                                                    let value = $event.target.value;
                                                    $wire.changeTicketDueDate({{ $ticket->id }}, value === '' ? null : value)
                                                } else {
                                                    $event.target.value = '{{ $ticket->due_date?->toDateString() }}'
                                                }
                                            "
                                            value="{{ $ticket->due_date?->toDateString() }}"
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        />
                                    @else
                                        {{ $ticket->due_date?->toDateString() ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $ticket->created_at?->toDateTimeString() }}</td>
                                @if ($session->isFullAccess())
                                    <td class="py-2 pr-4">
                                        {{-- Phase 11: the first destructive Full Access control. Same
                                             confirm()-gated pattern as every other write control on this
                                             page, but x-on:click (a button, not a <select>/<input>) —
                                             mirrors sprintStart()/sprintStop()'s buttons below. The
                                             confirm message names the ticket and the organization, and
                                             states the actual, verified impact (soft-delete/restore —
                                             TicketObserver::deleted()/restored() only invalidate cached
                                             statistics, confirmed by reading it in full; nothing else is
                                             claimed here). Delete is offered only for a live ticket,
                                             Restore only for an already-trashed one, matching how every
                                             other precondition-gated control on this page (e.g. sprint
                                             start/stop) only offers the transition the current state
                                             actually allows. --}}
                                        @if (! $ticket->trashed())
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js(__('Delete ticket :ticket from :organization? The ticket will be soft-deleted (moved to trash) and can be restored later.', ['ticket' => $ticket->name, 'organization' => $session->organization->name])))) {
                                                        $wire.deleteTicket({{ $ticket->id }})
                                                    }
                                                "
                                                class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js(__('Restore ticket :ticket in :organization? The ticket will be moved out of trash and become visible again.', ['ticket' => $ticket->name, 'organization' => $session->organization->name])))) {
                                                        $wire.restoreTicket({{ $ticket->id }})
                                                    }
                                                "
                                                class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                            >
                                                {{ __('Restore') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $session->isFullAccess() ? 10 : 8 }}" class="py-4 text-gray-500">{{ __('No tickets yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Sprints --}}
            @php($sprintsForBulk = $this->sprints())
            @php($bulkDeleteSprintConfirmTemplate = __('Delete %COUNT% selected sprint(s) from :organization? Items: %ITEMS%. Sprints will be soft-deleted (moved to trash) and can be restored later.', ['organization' => $session->organization->name]))
            @php($bulkRestoreSprintConfirmTemplate = __('Restore %COUNT% selected sprint(s) in :organization? Items: %ITEMS%. Sprints will be moved out of trash and become visible again. Any linked epic that was also deleted separately will be restored along with its sprint.', ['organization' => $session->organization->name]))
            <div class="flex flex-col gap-2" x-data="{ selectedSprintIds: [] }">
                <h3 class="text-base font-medium">{{ __('Sprints') }}</h3>

                @if ($session->isFullAccess())
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500" x-show="selectedSprintIds.length > 0" x-text="selectedSprintIds.length + ' {{ __('selected') }}'"></span>
                        <button
                            type="button"
                            x-on:click="
                                let items = Array.from($root.querySelectorAll('[data-sprint-checkbox]:checked')).map((cb) => cb.dataset.sprintName + (cb.dataset.sprintState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkDeleteSprintConfirmTemplate).replace('%COUNT%', selectedSprintIds.length).replace('%ITEMS%', items.join(', '));
                                if (confirm(message)) {
                                    $wire.bulkDeleteSprint(selectedSprintIds);
                                    selectedSprintIds = [];
                                }
                            "
                            class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                        >
                            {{ __('Bulk Delete') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="
                                let items = Array.from($root.querySelectorAll('[data-sprint-checkbox]:checked')).map((cb) => cb.dataset.sprintName + (cb.dataset.sprintState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkRestoreSprintConfirmTemplate).replace('%COUNT%', selectedSprintIds.length).replace('%ITEMS%', items.join(', '));
                                if (confirm(message)) {
                                    $wire.bulkRestoreSprint(selectedSprintIds);
                                    selectedSprintIds = [];
                                }
                            "
                            class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400"
                        >
                            {{ __('Bulk Restore') }}
                        </button>
                    </div>
                @endif

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            @if ($session->isFullAccess())
                                <th class="py-2 pr-4">
                                    <input
                                        type="checkbox"
                                        x-on:change="selectedSprintIds = $event.target.checked ? @js($sprintsForBulk->pluck('id')->all()) : []"
                                    />
                                    <span class="sr-only">{{ __('Select all') }}</span>
                                </th>
                            @endif
                            <th class="py-2 pr-4">{{ __('Sprints') }}</th>
                            <th class="py-2 pr-4">{{ __('Projects') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Sprint started at') }}</th>
                            <th class="py-2 pr-4">{{ __('Sprint ended at') }}</th>
                            <th class="py-2 pr-4">{{ __('Todo') }}</th>
                            <th class="py-2 pr-4">{{ __('In progress') }}</th>
                            <th class="py-2 pr-4">{{ __('Done') }}</th>
                            @if ($session->isFullAccess())
                                <th class="py-2 pr-4">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sprintsForBulk as $sprint)
                            @php($breakdown = $this->sprintStatusBreakdown($sprint))
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @if ($session->isFullAccess())
                                    <td class="py-2 pr-4">
                                        <input
                                            type="checkbox"
                                            data-sprint-checkbox
                                            data-sprint-name="{{ $sprint->name }}"
                                            data-sprint-state="{{ $sprint->trashed() ? 'trashed' : 'active' }}"
                                            value="{{ $sprint->id }}"
                                            x-model.number="selectedSprintIds"
                                        />
                                    </td>
                                @endif
                                <td class="py-2 pr-4">{{ $sprint->name }}</td>
                                <td class="py-2 pr-4">{{ $sprint->project?->name }}</td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Only the transition the current state actually allows is
                                             offered — mirrors SprintsRelationManager's own start/stop
                                             row actions' ->visible() rules exactly, so this control never
                                             shows a button that sprintStart()/sprintStop() would then
                                             reject as an invalid state. --}}
                                        @if (! $sprint->started_at && ! $sprint->ended_at)
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js(__('Are you sure you want to start this sprint?')))) {
                                                        $wire.sprintStart({{ $sprint->id }})
                                                    }
                                                "
                                                class="text-xs px-2 py-1 rounded-md bg-success-500 text-white"
                                            >
                                                {{ __('Start sprint') }}
                                            </button>
                                        @elseif ($sprint->started_at && ! $sprint->ended_at)
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js(__('Are you sure you want to stop this sprint?')))) {
                                                        $wire.sprintStop({{ $sprint->id }})
                                                    }
                                                "
                                                class="text-xs px-2 py-1 rounded-md bg-danger-500 text-white"
                                            >
                                                {{ __('Stop sprint') }}
                                            </button>
                                        @else
                                            {{ __('Ended') }}
                                        @endif
                                    @elseif ($sprint->ended_at)
                                        {{ __('Ended') }}
                                    @elseif ($sprint->started_at)
                                        {{ __('Running') }}
                                    @else
                                        {{ __('Not started') }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Both dates are read off the row at change time and sent
                                             together — changeSprintDates() validates them as a pair,
                                             the same cross-field rule SprintForm's own DatePicker pair
                                             already enforces. --}}
                                        <input
                                            type="date"
                                            data-field="starts_at"
                                            x-data
                                            x-on:change="
                                                let startsAt = $event.target.value;
                                                let endsAt = $el.closest('tr').querySelector('[data-field=ends_at]').value;
                                                if (confirm(@js(__('Are you sure you want to change this sprint\'s dates?')))) {
                                                    $wire.changeSprintDates({{ $sprint->id }}, startsAt, endsAt)
                                                } else {
                                                    $event.target.value = '{{ $sprint->starts_at?->toDateString() }}'
                                                }
                                            "
                                            value="{{ $sprint->starts_at?->toDateString() }}"
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        />
                                    @else
                                        {{ $sprint->starts_at?->toDateString() }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        <input
                                            type="date"
                                            data-field="ends_at"
                                            x-data
                                            x-on:change="
                                                let endsAt = $event.target.value;
                                                let startsAt = $el.closest('tr').querySelector('[data-field=starts_at]').value;
                                                if (confirm(@js(__('Are you sure you want to change this sprint\'s dates?')))) {
                                                    $wire.changeSprintDates({{ $sprint->id }}, startsAt, endsAt)
                                                } else {
                                                    $event.target.value = '{{ $sprint->ends_at?->toDateString() }}'
                                                }
                                            "
                                            value="{{ $sprint->ends_at?->toDateString() }}"
                                            class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                        />
                                    @else
                                        {{ $sprint->ends_at?->toDateString() }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $breakdown['todo'] }}</td>
                                <td class="py-2 pr-4">{{ $breakdown['in_progress'] }}</td>
                                <td class="py-2 pr-4">{{ $breakdown['done'] }}</td>
                                @if ($session->isFullAccess())
                                    <td class="py-2 pr-4">
                                        {{-- Phase 12: the second destructive Full Access control, same
                                             confirm()-gated button pattern as Delete/Restore Ticket
                                             (Phase 11). deleteSprint() never touches the sprint's mirrored
                                             epic (App\Observers\SprintObserver has no deleting()/deleted()
                                             hook, confirmed in full during Phase 12 inspection), so the
                                             delete confirm names only the sprint and organization.
                                             restoreSprint() can genuinely cascade-restore the mirrored
                                             epic if it was independently trashed in the meantime (e.g. via
                                             the Road Map's own Epic delete) — $sprint->epic is eager
                                             loaded withTrashed() in sprints() specifically so this message
                                             can say so truthfully instead of guessing or staying silent
                                             about a real impact. --}}
                                        @if (! $sprint->trashed())
                                            @php($sprintDeleteConfirm = __('Delete sprint :sprint from :organization? The sprint will be soft-deleted (moved to trash) and can be restored later.', ['sprint' => $sprint->name, 'organization' => $session->organization->name]))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js($sprintDeleteConfirm))) {
                                                        $wire.deleteSprint({{ $sprint->id }})
                                                    }
                                                "
                                                class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        @else
                                            @php($sprintRestoreConfirm = ($sprint->epic && $sprint->epic->trashed())
                                                ? __('Restore sprint :sprint in :organization? The sprint will be moved out of trash and become visible again. Its linked epic :epic, which was also deleted separately, will be restored along with it.', ['sprint' => $sprint->name, 'organization' => $session->organization->name, 'epic' => $sprint->epic->name])
                                                : __('Restore sprint :sprint in :organization? The sprint will be moved out of trash and become visible again.', ['sprint' => $sprint->name, 'organization' => $session->organization->name]))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="
                                                    if (confirm(@js($sprintRestoreConfirm))) {
                                                        $wire.restoreSprint({{ $sprint->id }})
                                                    }
                                                "
                                                class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                            >
                                                {{ __('Restore') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $session->isFullAccess() ? 10 : 8 }}" class="py-4 text-gray-500">{{ __('No sprints yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Members --}}
            <div class="flex flex-col gap-2">
                <h3 class="text-base font-medium">{{ __('Organization members') }}</h3>

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Name') }}</th>
                            <th class="py-2 pr-4">{{ __('Email') }}</th>
                            <th class="py-2 pr-4">{{ __('Role') }}</th>
                            <th class="py-2 pr-4">{{ __('Joined at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->members() as $member)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4">{{ $member->name }}</td>
                                <td class="py-2 pr-4">{{ $member->email }}</td>
                                <td class="py-2 pr-4">{{ config('system.organizations.affectations.roles.list')[$member->pivot->role] ?? $member->pivot->role }}</td>
                                <td class="py-2 pr-4">{{ $member->pivot->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-gray-500">{{ __('No members yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Recent activity --}}
            <div class="flex flex-col gap-2">
                <h3 class="text-base font-medium">{{ __('Recent activity') }}</h3>

                <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg">
                    @forelse ($this->recentActivity() as $activity)
                        <div class="flex flex-col px-4 py-3 text-sm">
                            <span>
                                {{ __(':user changed :ticket status from :old to :new', [
                                    'user' => $activity->user?->name ?? __('System'),
                                    'ticket' => $activity->ticket?->code ?? '—',
                                    'old' => $activity->oldStatus?->name ?? '—',
                                    'new' => $activity->newStatus?->name ?? '—',
                                ]) }}
                            </span>
                            <span class="text-xs text-gray-500">{{ $activity->created_at?->diffForHumans() }}</span>
                        </div>
                    @empty
                        <div class="px-4 py-4 text-sm text-gray-500">{{ __('No recent activity yet') }}</div>
                    @endforelse
                </div>
            </div>

            <div>
                <x-filament::button color="danger" wire:click="endSession">
                    {{ __('Akhiri Sesi') }}
                </x-filament::button>
            </div>
        </div>
    @endif

</x-filament::page>

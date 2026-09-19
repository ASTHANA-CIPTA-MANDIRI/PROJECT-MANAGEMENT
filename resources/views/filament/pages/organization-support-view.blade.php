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
                    {{ $session->isSupportAction() ? __('Support level: Support Action') : __('Support level: Read Only') }}
                </span>
                {{-- Phase 1 of Support Action: the level above is stored and displayed,
                     but no write capability exists yet for either level — this page
                     stays fully read-only regardless of what was picked when the
                     session started. --}}
                <span class="font-normal">{{ __('Read Only') }}</span>
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
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->projects() as $project)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-gray-500">{{ __('No projects yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Tickets --}}
            <div class="flex flex-col gap-2">
                <h3 class="text-base font-medium">{{ __('Tickets') }}</h3>

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Ticket name') }}</th>
                            <th class="py-2 pr-4">{{ __('Projects') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Priority') }}</th>
                            <th class="py-2 pr-4">{{ __('Labels') }}</th>
                            <th class="py-2 pr-4">{{ __('Responsible') }}</th>
                            <th class="py-2 pr-4">{{ __('Due date') }}</th>
                            <th class="py-2 pr-4">{{ __('Created at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->tickets() as $ticket)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
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
                                    @if ($session->isSupportAction())
                                        {{-- Support Action only: a confirm() gate sits in front of the
                                             Livewire call itself (not just a UI affordance) — canceling
                                             resets the <select> back to the ticket's current status so
                                             the control never shows a value that was not actually saved. --}}
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-gray-500">{{ __('No tickets yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Sprints --}}
            <div class="flex flex-col gap-2">
                <h3 class="text-base font-medium">{{ __('Sprints') }}</h3>

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Sprints') }}</th>
                            <th class="py-2 pr-4">{{ __('Projects') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Sprint started at') }}</th>
                            <th class="py-2 pr-4">{{ __('Sprint ended at') }}</th>
                            <th class="py-2 pr-4">{{ __('Todo') }}</th>
                            <th class="py-2 pr-4">{{ __('In progress') }}</th>
                            <th class="py-2 pr-4">{{ __('Done') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->sprints() as $sprint)
                            @php($breakdown = $this->sprintStatusBreakdown($sprint))
                            <tr class="border-b border-gray-100 dark:border-gray-800">
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-gray-500">{{ __('No sprints yet') }}</td>
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

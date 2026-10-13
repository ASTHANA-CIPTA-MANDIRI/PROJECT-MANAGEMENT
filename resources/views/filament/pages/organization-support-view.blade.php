<x-filament::page>

    @php($session = $this->session())

    @if ($session)
        @php($overview = $this->overview())

        <div class="w-full flex flex-col gap-8">

            <?php
                $level = $session->isFullAccess() ? 'full_access' : ($session->isSupportAction() ? 'support_action' : 'read_only');
                $levelText = $session->isFullAccess()
                    ? __('Support level: Full Access')
                    : ($session->isSupportAction() ? __('Support level: Support Action') : __('Support level: Read Only'));
                $levelTone = ['read_only' => 'gray', 'support_action' => 'yellow', 'full_access' => 'red'][$level];
                $can = [
                    'read_only' => [__('See the overview, projects, tickets, sprints, members and activity')],
                    'support_action' => [
                        __('Change ticket status, priority, assignee, due date, title and labels'),
                        __('Change a project\'s status'),
                        __('Start or stop a sprint and change its dates'),
                    ],
                    'full_access' => [
                        __('Change ticket status'),
                        __('Move projects, tickets and sprints to the trash and bring them back (also in bulk)'),
                    ],
                ][$level];
                $cannot = [
                    'read_only' => [__('Change anything: this level only looks')],
                    'support_action' => [__('Move anything to the trash')],
                    'full_access' => [__('Edit priority, labels, assignee, dates or titles: these are not part of this level')],
                ][$level];
            ?>

            <section class="rk-sp__head rk-sp__head--{{ $levelTone }}">
                <div class="rk-sp__head-top">
                    <span class="rk-sp__tile">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($session->organization->name, 0, 1)) }}</span>
                    <div class="rk-sp__head-title">
                        <h2>{{ $session->organization->name }}</h2>
                        <span class="rk-sp__level-label">{{ __('Support level') }}</span>
                        <span class="rk-sp__chip">{{ $levelText }}</span>
                    </div>
                    <x-filament::button color="danger" wire:click="endSession"
                        data-swal-confirm="{{ __('End this support session?') }}" data-swal-severity="danger">
                        {{ __('Akhiri Sesi') }}
                    </x-filament::button>
                </div>

                <dl class="rk-sp__meta">
                    <div><dt><x-heroicon-o-chat-alt class="h-4 w-4" /> {{ __('Alasan') }}</dt><dd>{{ $session->reason }}</dd></div>
                    <div><dt><x-heroicon-o-play class="h-4 w-4" /> {{ __('Started at') }}</dt><dd>{{ $session->started_at->diffForHumans() }}</dd></div>
                    <div><dt><x-heroicon-o-clock class="h-4 w-4" /> {{ __('Expires at') }}</dt><dd>{{ $session->expires_at->diffForHumans() }}</dd></div>
                </dl>

                <div class="rk-sp__can">
                    <div>
                        <b>{{ __('What you can do here') }}</b>
                        <ul>@foreach ($can as $line)<li class="is-yes"><x-heroicon-s-check-circle class="h-4 w-4" /> {{ $line }}</li>@endforeach</ul>
                    </div>
                    <div>
                        <b>{{ __('What you cannot do here') }}</b>
                        <ul>@foreach ($cannot as $line)<li class="is-no"><x-heroicon-s-x-circle class="h-4 w-4" /> {{ $line }}</li>@endforeach</ul>
                    </div>
                </div>

                @if ($level !== 'read_only')
                    <p class="rk-sp__audit"><x-heroicon-o-shield-check class="h-4 w-4" /> {{ __('Every change asks for confirmation first and is written to the session\'s audit log.') }}</p>
                @endif
            </section>

            <nav class="rk-sp__nav">
                <a href="#sp-projects">{{ __('Projects') }}</a>
                <a href="#sp-tickets">{{ __('Tickets') }}</a>
                <a href="#sp-sprints">{{ __('Sprints') }}</a>
                <a href="#sp-members">{{ __('Organization members') }}</a>
                <a href="#sp-activity">{{ __('Recent activity') }}</a>
            </nav>

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
            @php($projectsList = $this->projects())
            <div class="flex flex-col gap-2">
                <span class="rk-sp__anchor" id="sp-projects"></span>
                <div class="rk-sp__section-head">
                    <h3>{{ __('Projects') }} <span class="rk-sp__count">{{ $projectsList->count() }}</span></h3>
                    <p>{{ $session->isFullAccess() ? __('Projects can be moved to the trash and restored. Deleting one also removes its tickets, sprints and epics (all restorable).') : ($session->isSupportAction() ? __('A project\'s status can be changed here.') : __('Read only.')) }}</p>
                </div>

                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Project') }}</th>
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
                        @forelse ($projectsList as $project)
                            <tr class="border-b border-gray-100 dark:border-gray-800 @if ($project->trashed()) opacity-50 @endif">
                                <td class="py-2 pr-4">{{ $project->name }} @if ($project->trashed())<span class="rk-sp__trash">{{ __('In trash') }}</span>@endif</td>
                                <td class="py-2 pr-4">{{ $project->ticket_prefix }}</td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as Ticket Status above. --}}
                                        <x-support-select
                                            :options="$this->projectStatusOptions()->map(fn ($o) => ['id' => $o->id, 'label' => $o->name, 'color' => $o->color])->all()"
                                            :current="$project->status_id"
                                            method="changeProjectStatus"
                                            :record-id="$project->id"
                                            :confirm="__('Are you sure you want to change this project\'s status?')" />
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
                                                    RencanakanAlert.confirm({ severity: 'danger', text: @js($projectDeleteConfirm) }).then((confirmed) => { if (confirmed) {
                                                        $wire.deleteProject({{ $project->id }})
                                                    } })
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
                                                    RencanakanAlert.confirm({ severity: 'warning', text: @js($projectRestoreConfirm) }).then((confirmed) => { if (confirmed) {
                                                        $wire.restoreProject({{ $project->id }})
                                                    } })
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
                <span class="rk-sp__anchor" id="sp-tickets"></span>
                <div class="rk-sp__section-head">
                    <h3>{{ __('Tickets') }} <span class="rk-sp__count">{{ $ticketsForBulk->count() }}</span></h3>
                    <p>{{ $session->isFullAccess() ? __('A ticket\'s status can be changed; tickets can be moved to the trash and restored, also in bulk. Priority, labels, assignee and dates cannot be edited at this level.') : ($session->isSupportAction() ? __('Title, status, priority, labels, assignee and due date can be changed right in the table. Each change asks for confirmation.') : __('Read only.')) }}</p>
                </div>

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
                            x-on:click="(() => {
                                let items = Array.from($root.querySelectorAll('[data-ticket-checkbox]:checked')).map((cb) => cb.dataset.ticketName + (cb.dataset.ticketState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkDeleteTicketConfirmTemplate).replace('%COUNT%', selectedTicketIds.length).replace('%ITEMS%', items.join(', '));
                                RencanakanAlert.confirm({ severity: 'danger', text: message }).then((confirmed) => { if (confirmed) {
                                    $wire.bulkDeleteTicket(selectedTicketIds);
                                    selectedTicketIds = [];
                                } })
})()"
                            class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                        >
                            {{ __('Bulk Delete') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="(() => {
                                let items = Array.from($root.querySelectorAll('[data-ticket-checkbox]:checked')).map((cb) => cb.dataset.ticketName + (cb.dataset.ticketState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkRestoreTicketConfirmTemplate).replace('%COUNT%', selectedTicketIds.length).replace('%ITEMS%', items.join(', '));
                                RencanakanAlert.confirm({ severity: 'warning', text: message }).then((confirmed) => { if (confirmed) {
                                    $wire.bulkRestoreTicket(selectedTicketIds);
                                    selectedTicketIds = [];
                                } })
})()"
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
                                    {{ $ticket->code }} @if ($ticket->trashed())<span class="rk-sp__trash">{{ __('In trash') }}</span>@endif —
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
                                                RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to change this ticket\'s title?')) }).then((confirmed) => { if (confirmed) {
                                                    $wire.changeTicketTitle({{ $ticket->id }}, $event.target.value)
                                                } else {
                                                    $event.target.value = @js($ticket->name)
                                                } })
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
                                        <x-support-select
                                            :options="$this->ticketStatusOptions($ticket)->map(fn ($o) => ['id' => $o->id, 'label' => $o->name, 'color' => $o->color])->all()"
                                            :current="$ticket->status_id"
                                            method="changeTicketStatus"
                                            :record-id="$ticket->id"
                                            :confirm="__('Are you sure you want to change this ticket\'s status?')" />
                                    @elseif ($ticket->status)
                                        @include('components.color-badge', ['color' => $ticket->status->color, 'label' => $ticket->status->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($session->isSupportAction())
                                        {{-- Same confirm()-gated pattern as the Status select above:
                                             canceling resets the <select> back to the ticket's current
                                             priority so it never shows a value that was not saved. --}}
                                        <x-support-select
                                            :options="$this->priorityOptions()->map(fn ($o) => ['id' => $o->id, 'label' => $o->name, 'color' => $o->color])->all()"
                                            :current="$ticket->priority_id"
                                            method="changeTicketPriority"
                                            :record-id="$ticket->id"
                                            :confirm="__('Are you sure you want to change this ticket\'s priority?')" />
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
                                        <x-support-multiselect
                                            :options="$this->labelOptions()->map(fn ($o) => ['id' => $o->id, 'label' => $o->name, 'color' => $o->color])->all()"
                                            :current="$ticket->labels->pluck('id')->all()"
                                            method="changeTicketLabels"
                                            :record-id="$ticket->id"
                                            :confirm="__('Are you sure you want to change this ticket\'s labels?')" />
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
                                        <x-support-select
                                            :options="$this->responsibleOptions($ticket->id)->map(fn ($o) => ['id' => $o->id, 'label' => $o->name])->prepend(['id' => null, 'label' => __('Unassigned')])->values()->all()"
                                            :current="$ticket->responsible_id"
                                            method="changeTicketResponsible"
                                            :record-id="$ticket->id"
                                            :confirm="__('Are you sure you want to change this ticket\'s responsible?')" />
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
                                                RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to change this ticket\'s due date?')) }).then((confirmed) => { if (confirmed) {
                                                    let value = $event.target.value;
                                                    $wire.changeTicketDueDate({{ $ticket->id }}, value === '' ? null : value)
                                                } else {
                                                    $event.target.value = '{{ $ticket->due_date?->toDateString() }}'
                                                } })
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
                                                    RencanakanAlert.confirm({ severity: 'danger', text: @js(__('Delete ticket :ticket from :organization? The ticket will be soft-deleted (moved to trash) and can be restored later.', ['ticket' => $ticket->name, 'organization' => $session->organization->name])) }).then((confirmed) => { if (confirmed) {
                                                        $wire.deleteTicket({{ $ticket->id }})
                                                    } })
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
                                                    RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Restore ticket :ticket in :organization? The ticket will be moved out of trash and become visible again.', ['ticket' => $ticket->name, 'organization' => $session->organization->name])) }).then((confirmed) => { if (confirmed) {
                                                        $wire.restoreTicket({{ $ticket->id }})
                                                    } })
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
                <span class="rk-sp__anchor" id="sp-sprints"></span>
                <div class="rk-sp__section-head">
                    <h3>{{ __('Sprints') }} <span class="rk-sp__count">{{ $sprintsForBulk->count() }}</span></h3>
                    <p>{{ $session->isFullAccess() ? __('Sprints can be moved to the trash and restored, also in bulk.') : ($session->isSupportAction() ? __('Sprints can be started or stopped and their dates changed.') : __('Read only.')) }}</p>
                </div>

                @if ($session->isFullAccess())
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500" x-show="selectedSprintIds.length > 0" x-text="selectedSprintIds.length + ' {{ __('selected') }}'"></span>
                        <button
                            type="button"
                            x-on:click="(() => {
                                let items = Array.from($root.querySelectorAll('[data-sprint-checkbox]:checked')).map((cb) => cb.dataset.sprintName + (cb.dataset.sprintState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkDeleteSprintConfirmTemplate).replace('%COUNT%', selectedSprintIds.length).replace('%ITEMS%', items.join(', '));
                                RencanakanAlert.confirm({ severity: 'danger', text: message }).then((confirmed) => { if (confirmed) {
                                    $wire.bulkDeleteSprint(selectedSprintIds);
                                    selectedSprintIds = [];
                                } })
})()"
                            class="text-sm text-danger-600 hover:text-danger-700 dark:text-danger-400"
                        >
                            {{ __('Bulk Delete') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="(() => {
                                let items = Array.from($root.querySelectorAll('[data-sprint-checkbox]:checked')).map((cb) => cb.dataset.sprintName + (cb.dataset.sprintState === 'trashed' ? ' ({{ __('trashed') }})' : ''));
                                let message = @js($bulkRestoreSprintConfirmTemplate).replace('%COUNT%', selectedSprintIds.length).replace('%ITEMS%', items.join(', '));
                                RencanakanAlert.confirm({ severity: 'warning', text: message }).then((confirmed) => { if (confirmed) {
                                    $wire.bulkRestoreSprint(selectedSprintIds);
                                    selectedSprintIds = [];
                                } })
})()"
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
                                <td class="py-2 pr-4">{{ $sprint->name }} @if ($sprint->trashed())<span class="rk-sp__trash">{{ __('In trash') }}</span>@endif</td>
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
                                                    RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to start this sprint?')) }).then((confirmed) => { if (confirmed) {
                                                        $wire.sprintStart({{ $sprint->id }})
                                                    } })
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
                                                    RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to stop this sprint?')) }).then((confirmed) => { if (confirmed) {
                                                        $wire.sprintStop({{ $sprint->id }})
                                                    } })
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
                                            x-on:change="(() => {
                                                let startsAt = $event.target.value;
                                                let endsAt = $el.closest('tr').querySelector('[data-field=ends_at]').value;
                                                RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to change this sprint\'s dates?')) }).then((confirmed) => { if (confirmed) {
                                                    $wire.changeSprintDates({{ $sprint->id }}, startsAt, endsAt)
                                                } else {
                                                    $event.target.value = '{{ $sprint->starts_at?->toDateString() }}'
                                                } })
})()"
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
                                            x-on:change="(() => {
                                                let endsAt = $event.target.value;
                                                let startsAt = $el.closest('tr').querySelector('[data-field=starts_at]').value;
                                                RencanakanAlert.confirm({ severity: 'warning', text: @js(__('Are you sure you want to change this sprint\'s dates?')) }).then((confirmed) => { if (confirmed) {
                                                    $wire.changeSprintDates({{ $sprint->id }}, startsAt, endsAt)
                                                } else {
                                                    $event.target.value = '{{ $sprint->ends_at?->toDateString() }}'
                                                } })
})()"
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
                                                    RencanakanAlert.confirm({ severity: 'danger', text: @js($sprintDeleteConfirm) }).then((confirmed) => { if (confirmed) {
                                                        $wire.deleteSprint({{ $sprint->id }})
                                                    } })
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
                                                    RencanakanAlert.confirm({ severity: 'warning', text: @js($sprintRestoreConfirm) }).then((confirmed) => { if (confirmed) {
                                                        $wire.restoreSprint({{ $sprint->id }})
                                                    } })
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
            @php($membersList = $this->members())
            <div class="flex flex-col gap-2">
                <span class="rk-sp__anchor" id="sp-members"></span>
                <div class="rk-sp__section-head">
                    <h3>{{ __('Organization members') }} <span class="rk-sp__count">{{ $membersList->count() }}</span></h3>
                </div>

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
                        @forelse ($membersList as $member)
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
            @php($activityList = $this->recentActivity())
            <div class="flex flex-col gap-2">
                <span class="rk-sp__anchor" id="sp-activity"></span>
                <div class="rk-sp__section-head">
                    <h3>{{ __('Recent activity') }} <span class="rk-sp__count">{{ $activityList->count() }}</span></h3>
                    <p>{{ __('Latest 8 changes from the last 30 days.') }}</p>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg">
                    @forelse ($activityList as $activity)
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
        </div>
    @endif

</x-filament::page>

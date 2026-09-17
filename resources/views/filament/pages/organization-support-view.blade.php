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
                                <td class="py-2 pr-4">{{ $project->tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->open_tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->overdue_tickets_count }}</td>
                                <td class="py-2 pr-4">{{ $project->sprints->first()?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $project->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-4 text-gray-500">{{ __('No projects yet') }}</td>
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
                            <th class="py-2 pr-4">{{ __('Responsible') }}</th>
                            <th class="py-2 pr-4">{{ __('Created at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->tickets() as $ticket)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4">{{ $ticket->code }} — {{ $ticket->name }}</td>
                                <td class="py-2 pr-4">{{ $ticket->project?->name }}</td>
                                <td class="py-2 pr-4">
                                    @if ($ticket->status)
                                        @include('components.color-badge', ['color' => $ticket->status->color, 'label' => $ticket->status->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($ticket->priority)
                                        @include('components.color-badge', ['color' => $ticket->priority->color, 'label' => $ticket->priority->name])
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $ticket->responsible?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $ticket->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-4 text-gray-500">{{ __('No tickets yet') }}</td>
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
                                    @if ($sprint->ended_at)
                                        {{ __('Ended') }}
                                    @elseif ($sprint->started_at)
                                        {{ __('Running') }}
                                    @else
                                        {{ __('Not started') }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $sprint->starts_at?->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $sprint->ends_at?->toDateString() }}</td>
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

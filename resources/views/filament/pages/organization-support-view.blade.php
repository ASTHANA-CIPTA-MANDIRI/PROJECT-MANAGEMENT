<x-filament::page>

    @php($session = $this->session())

    @if ($session)
        <div class="w-full flex flex-col gap-4">
            <div class="bg-warning-500 border border-warning-600 text-white py-3 px-4 text-sm rounded-lg flex flex-col gap-1">
                <span class="font-medium">{{ $session->organization->name }}</span>
                <span class="font-normal">{{ __('Alasan') }}: {{ $session->reason }}</span>
                <span class="font-normal">
                    {{ __('Started at') }}: {{ $session->started_at->diffForHumans() }}
                    &middot;
                    {{ __('Expires at') }}: {{ $session->expires_at->diffForHumans() }}
                </span>
            </div>

            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-4">{{ __('Organization') }}</th>
                        <th class="py-2 pr-4">{{ __('Ticket prefix') }}</th>
                        <th class="py-2 pr-4">{{ __('Created at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($session->organization->projects as $project)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">{{ $project->name }}</td>
                            <td class="py-2 pr-4">{{ $project->ticket_prefix }}</td>
                            <td class="py-2 pr-4">{{ $project->created_at?->toDateTimeString() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 text-gray-500">{{ __('No projects yet') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div>
                <x-filament::button color="danger" wire:click="endSession">
                    {{ __('Akhiri Sesi') }}
                </x-filament::button>
            </div>
        </div>
    @endif

</x-filament::page>

<div class="px-6 pb-4">
    @if ($organizations->isEmpty())
        {{-- No organization membership at all: nothing to switch between. --}}
    @elseif ($organizations->count() === 1)
        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium text-gray-400 dark:text-gray-500">
                {{ __('Organization') }}
            </span>
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ $organizations->first()->name }}
            </span>
        </div>
    @else
        <div class="flex flex-col gap-1">
            <label for="organization-switcher" class="text-xs font-medium text-gray-400 dark:text-gray-500">
                {{ __('Organization') }}
            </label>
            <select
                id="organization-switcher"
                class="filament-forms-select-component block w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                wire:change="switchOrganization($event.target.value)"
            >
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected($current?->is($organization))>
                        {{ $organization->name }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
</div>

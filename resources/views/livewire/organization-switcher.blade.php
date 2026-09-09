<div class="px-6 pb-4 flex flex-col gap-2">
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

    {{-- Phase 5.4.4F (Option B): only offered to a user with zero
         organization memberships — OrganizationPolicy::create() denies
         everyone else, so hiding this link for them keeps the UI honest
         about what the backend actually allows, rather than offering a
         link that always 403s for an existing member/owner/admin. --}}
    @can('create', \App\Models\Organization::class)
        <a
            href="{{ route('filament.pages.create-organization') }}"
            class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
        >
            + {{ __('Create organization') }}
        </a>
    @endcan
</div>

<x-filament::page>

    <p class="rk-lead">{{ __('Use this section to login into your jira account and import tickets to this application') }}</p>

    <div class="rk-notice rk-notice--warning">
        <span class="font-medium">{{ __('Important:') }}</span>
        <span class="font-normal">{{ __('Before you can import jira tickets you need to have all the referentials configured') }}</span>
    </div>

    <form wire:submit.prevent="import">
        {{ $this->form }}
    </form>

</x-filament::page>

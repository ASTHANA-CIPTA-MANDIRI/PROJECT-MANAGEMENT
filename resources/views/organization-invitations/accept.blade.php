<x-filament::layouts.base :title="__('Organization invitation')">
    @livewire('accept-organization-invitation', ['token' => $token])
</x-filament::layouts.base>

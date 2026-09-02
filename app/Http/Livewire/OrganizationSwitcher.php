<?php

namespace App\Http\Livewire;

use App\Support\OrganizationContext;
use Livewire\Component;

/**
 * Phase 3C — the UI half of App\Support\OrganizationContext (Phase 3A),
 * which already had switch()/current() but no way for a real user to reach
 * them. Rendered near the top of every Filament panel page via the
 * `sidebar.start` render hook (AppServiceProvider::boot()) — a Blade
 * injection point, not Filament's Tenancy feature, which ADR 0001
 * deliberately left off.
 */
class OrganizationSwitcher extends Component
{
    /**
     * The organization id never arrives as a bound public property here —
     * it is passed as a plain method argument from the select's wire:change
     * payload, and OrganizationContext::switch() re-verifies membership
     * before it is trusted for anything. A crafted Livewire payload can pass
     * any value; it simply won't take effect.
     */
    public function switchOrganization(int $organizationId): void
    {
        if (! OrganizationContext::switch(auth()->user(), $organizationId)) {
            return;
        }

        // A full navigation, not a Livewire-only re-render: every Filament
        // table/widget/query on the page must recompute under the new
        // context rather than trusting already-hydrated component state.
        $this->redirect(url()->previous());
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.organization-switcher', [
            'organizations' => $user->organizations,
            'current' => OrganizationContext::current($user),
        ]);
    }
}

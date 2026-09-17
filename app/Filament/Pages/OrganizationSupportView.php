<?php

namespace App\Filament\Pages;

use App\Models\OrganizationSupportSession;
use App\Support\SupportSessionContext;

/**
 * The Super Admin's read-only window into an Organization's data while a
 * support session (App\Support\SupportSessionContext) is active — the
 * companion page PlatformOrganizations::startSupportSession redirects into.
 *
 * Deliberately read-only by construction: no write action is defined
 * anywhere on this page, so there is nothing here to gate with a Policy or
 * a Gate::before rule. It reads $organization->projects directly rather
 * than Project::accessibleBy()/Project::isAccessibleBy(), which is
 * intentional and safe specifically *because* no write path is exposed —
 * see docs/adr/0001-hybrid-multi-tenant-authorization.md's addendum after
 * Scenario D for why this is a separate side-channel from
 * App\Support\OrganizationContext rather than a bypass added to it.
 *
 * Access requires both an active support session *and* isSuperAdmin() —
 * userCanAccessPage() is re-run on every Livewire request by
 * AuthorizesPageAccess (see AuthorizedPage), so ending the session (or
 * letting it expire) immediately locks this page out again.
 */
class OrganizationSupportView extends AuthorizedPage
{
    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'platform/support-session';

    protected static string $view = 'filament.pages.organization-support-view';

    public static function userCanAccessPage(): bool
    {
        $user = auth()->user();

        return (bool) $user?->isSuperAdmin() && SupportSessionContext::current($user) !== null;
    }

    public function getHeading(): string
    {
        return __('Sesi Support');
    }

    public function session(): ?OrganizationSupportSession
    {
        return SupportSessionContext::current(auth()->user());
    }

    public function endSession(): void
    {
        SupportSessionContext::stop(auth()->user());

        $this->redirect(PlatformOrganizations::getUrl());
    }
}

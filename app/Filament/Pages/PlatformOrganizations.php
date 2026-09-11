<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Support\TrialGate;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform Super Admin only — a read-only, cross-organization view so the
 * operator of the SaaS product can monitor every Organization's trial status
 * without joining any of them as a member.
 *
 * Per ADR 0001 ("Platform" section): "A platform-level Filament panel that
 * lets the Platform Super Admin browse across organizations is explicitly
 * Level-1 territory ... must not rely on a single-organization
 * `setPermissionsTeamId()` context at all." This page therefore never touches
 * `App\Support\OrganizationContext` or `App\Policies\OrganizationPolicy` —
 * both are deliberately scoped to "the organization(s) this user belongs to,"
 * which is the wrong question here. Authorization below is overridden to
 * check `auth()->user()->isSuperAdmin()` directly instead — the same
 * non-team-scoped mechanism `RolePolicy`, `UserPolicy`, and
 * `RequireTwoFactorForSuperAdmins` already use for this exact class of check
 * (Scenario D of the Teams spike found a `team_id = NULL` role assignment
 * unsafe to rely on for a cross-organization actor), reused here rather than
 * inventing a new authorization path.
 *
 * Deliberately read-only: no edit/delete/member-management action exists on
 * this page. Managing one specific Organization as the Platform Super Admin
 * is a separate, not-yet-built feature — out of scope here on purpose.
 */
class PlatformOrganizations extends AuthorizedPage implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    /**
     * Unused: {@see userCanAccessPage()} is overridden below instead of the
     * permission-string mechanism {@see \App\Filament\Pages\Concerns\AuthorizesPageAccess}
     * normally reads from this property. See the class docblock for why a
     * flat Spatie permission is the wrong tool for a Platform-level page.
     */
    protected static ?string $permission = null;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'platform/organizations';

    protected static string $view = 'filament.pages.platform-organizations';

    /**
     * Overrides {@see \App\Filament\Pages\Concerns\AuthorizesPageAccess::userCanAccessPage()}.
     * Both `boot()` (runs on every Livewire request, not just mount) and
     * `shouldRegisterNavigation()` already call this method via late static
     * binding, so overriding it alone is enough to redirect the existing
     * enforcement machinery at `isSuperAdmin()` instead of a permission.
     */
    public static function userCanAccessPage(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Platform');
    }

    protected static function getNavigationLabel(): string
    {
        return __('Organizations');
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Organizations');
    }

    /**
     * Every Organization in the installation, regardless of the acting
     * user's own membership — the entire point of this page. `withCount`
     * avoids an N+1 for the member-count column; the `users` eager load is
     * pre-filtered to the owner pivot row only, so the owner column below
     * reads it without a second query per row.
     */
    protected function getTableQuery(): Builder
    {
        return Organization::query()
            ->withCount('users')
            ->with(['users' => fn ($query) => $query->wherePivot('role', 'owner')])
            // Trials closest to running out (or already expired) float to the
            // top; grandfathered organizations (trial_ends_at is null) have
            // no urgency at all, so they sort last regardless of direction.
            ->orderByRaw('trial_ends_at is null')
            ->orderBy('trial_ends_at');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('Organization'))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('owner')
                ->label(__('Owner'))
                ->getStateUsing(fn (Organization $record) => $record->users->first()?->name ?? __('No owner'))
                ->description(fn (Organization $record) => $record->users->first()?->email),

            Tables\Columns\TextColumn::make('users_count')
                ->label(__('Members'))
                ->sortable(),

            Tables\Columns\TextColumn::make('trial_status')
                ->label(__('Trial status'))
                ->getStateUsing(fn (Organization $record) => $this->trialStatusLabel($record))
                ->color(fn (Organization $record) => $this->trialStatusColor($record)),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No organizations yet');
    }

    /**
     * Mirrors App\Support\TrialGate::active()'s own three-way read of
     * trial_ends_at/isSubscribed(), rendered as the label a human needs
     * instead of a boolean. Calendar-day (not wall-clock) difference, so the
     * "N hari lagi" count does not depend on what time of day this page
     * happens to be rendered.
     */
    private function trialStatusLabel(Organization $organization): string
    {
        if ($organization->trial_ends_at === null) {
            return __('Grandfathered');
        }

        if ($organization->trial_ends_at->isFuture()) {
            $days = now()->startOfDay()->diffInDays($organization->trial_ends_at->copy()->startOfDay());

            return __('Trial active (:days days left)', ['days' => $days]);
        }

        // Past its trial window: TrialGate::active() is what the rest of the
        // app actually gates access on, so this reads the same source of
        // truth rather than re-deciding "expired but subscribed" locally.
        // Not reachable today (Organization::isSubscribed() is a Fase 7
        // stub that always returns false, so this branch is always "Trial
        // habis" for now) but kept distinct so the label stays correct once
        // billing exists.
        return TrialGate::active($organization) ? __('Subscribed') : __('Trial ended');
    }

    private function trialStatusColor(Organization $organization): string
    {
        if ($organization->trial_ends_at === null) {
            return 'gray';
        }

        if ($organization->trial_ends_at->isFuture()) {
            return 'success';
        }

        return $organization->isSubscribed() ? 'success' : 'danger';
    }
}

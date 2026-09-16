<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationDefaults;
use App\Support\TrialGate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform Super Admin only — a cross-organization view so the operator of
 * the SaaS product can monitor every Organization's trial status, and help
 * customers directly (rename a mistyped name, extend a trial, provision an
 * organization by hand, remove an abandoned one) without joining any of
 * them as a member.
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
 * inventing a new authorization path. `AuthorizesPageAccess` re-runs
 * `userCanAccessPage()` on every Livewire request (not just the first
 * render), so every action below is covered by the same check without
 * needing its own redundant Gate call.
 *
 * Create/Edit/Delete live here rather than a Resource for the same reason
 * OrganizationSettings does: this is a hand-built table with actions that
 * call domain logic directly, not a RelationManager whose abilities would
 * be checked against the wrong Policy. Deleting an Organization only ever
 * succeeds when it has zero Projects (including trashed ones) — every
 * organization-scoped lookup table (ticket_types, ticket_priorities,
 * project_statuses, labels, activities) cascade-deletes with it, and a
 * Project's `status_id`/`priority_id`/`type_id` foreign keys are NOT
 * cascading, so a Project would otherwise be left pointing at a row that no
 * longer exists — the deleteOrganization action refuses up front instead of
 * letting that happen.
 */
class PlatformOrganizations extends AuthorizedPage implements HasForms, Tables\Contracts\HasTable
{
    use InteractsWithForms;
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
     * Manual provisioning — e.g. an enterprise deal onboarded by hand
     * instead of self-serve signup. Mirrors CreateOrganization's own
     * create+owner+defaults transaction (Phase 5.2) exactly, except the
     * owner is a Select instead of always auth()->id(), since here it is
     * the Super Admin acting on someone else's behalf.
     */
    protected function getTableHeaderActions(): array
    {
        return [
            Tables\Actions\Action::make('createOrganization')
                ->label(__('Create organization'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('Create organization'))
                ->form([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->maxLength(255),

                    Select::make('owner_id')
                        ->label(__('Owner'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where(fn (Builder $query) => $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"]))
                        ->getOptionLabelUsing(fn ($value) => ($user = User::find($value))
                            ? "{$user->name} ({$user->email})"
                            : null)
                        ->helperText(__("This user becomes the organization's initial Owner.")),

                    DatePicker::make('trial_ends_at')
                        ->label(__('Trial ends at'))
                        ->helperText(__('Leave blank for an unlimited (grandfathered) organization.')),
                ])
                ->action(function (array $data): void {
                    $name = trim((string) $data['name']);

                    if ($name === '') {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('The organization name must not be blank.'),
                        ]);
                    }

                    $owner = User::find($data['owner_id'] ?? null);

                    if ($owner === null) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.owner_id' => __('Please choose an owner.'),
                        ]);
                    }

                    if (Organization::where('name', $name)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    try {
                        DB::transaction(function () use ($name, $owner, $data) {
                            $organization = Organization::create([
                                'name' => $name,
                                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                            ]);
                            $organization->users()->attach($owner->id, ['role' => 'owner']);
                            OrganizationDefaults::seed($organization);
                            $this->assignOwnerAccessRoleIfNone($owner);
                        });
                    } catch (QueryException $exception) {
                        // organizations.name is unique at the database level
                        // too — the final backstop against a race with the
                        // check above, same discipline as CreateOrganization.
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Organization created'))
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\Action::make('editOrganization')
                ->label(__('Edit'))
                ->icon('heroicon-o-pencil')
                ->modalHeading(__('Edit organization'))
                // Owner reassignment deliberately has no field here —
                // ownership transfer is a separate, not-yet-built feature
                // (same boundary OrganizationPolicy's addMember() docblock
                // already draws), not a side effect of an unrelated edit.
                // Filament 2's table actions have no fillForm() (a Filament
                // 3 API) — each field reads the record's current value via
                // its own ->default(), the same pattern changeRole() in
                // OrganizationSettings already uses.
                ->form([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->maxLength(255)
                        ->default(fn (Organization $record) => $record->name),

                    DatePicker::make('trial_ends_at')
                        ->label(__('Trial ends at'))
                        ->helperText(__('Leave blank for an unlimited (grandfathered) organization.'))
                        ->default(fn (Organization $record) => $record->trial_ends_at),
                ])
                ->action(function (Organization $record, array $data): void {
                    $name = trim((string) $data['name']);

                    if ($name === '') {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('The organization name must not be blank.'),
                        ]);
                    }

                    if (Organization::where('name', $name)->whereKeyNot($record->id)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    try {
                        $record->update([
                            'name' => $name,
                            'trial_ends_at' => $data['trial_ends_at'] ?? null,
                        ]);
                    } catch (QueryException $exception) {
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Organization updated'))
                        ->success()
                        ->send();
                }),

            Tables\Actions\Action::make('deleteOrganization')
                ->label(__('Delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Delete organization?'))
                // Not record-scoped ($record isn't resolvable yet at the
                // point Filament 2 evaluates shouldOpenModal() for a table
                // action - only ->default() form fields and ->action()
                // itself receive the mounted record) - kept generic instead
                // of interpolating the organization's name.
                ->modalSubheading(__('This permanently removes its memberships, invitations, and organization-scoped reference data (ticket types, priorities, statuses, labels, activities). An organization that still has projects cannot be deleted — reassign or delete them first.'))
                ->modalButton(__('Delete'))
                ->action(function (Organization $record): void {
                    // withTrashed() because a soft-deleted Project's
                    // status_id/priority_id/type_id foreign keys still point
                    // at this organization's lookup rows, so the cascade
                    // below would still violate them otherwise. This is the
                    // real guard (not just a friendlier message) — every
                    // Ticket lives under a Project, and every Project under
                    // this Organization, so zero Projects (trashed included)
                    // means nothing else can hold a foreign key into the
                    // lookup rows this delete cascades through.
                    if ($record->projects()->withTrashed()->exists()) {
                        Notification::make()
                            ->title(__('Cannot delete an organization that still has projects'))
                            ->body(__('Reassign or delete its projects first, then try again.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->delete();

                    Notification::make()
                        ->title(__('Organization deleted'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * organization_users.role='owner' (set right above this call) is the
     * tenant/authority axis; it grants nothing in Filament by itself -
     * User::canAccessFilament() gates purely on Spatie roles()->exists(),
     * same as OrganizationSettings::addExistingUser() already has to
     * account for. Unlike that method, this one never risks overwriting an
     * *active* Role: Spatie roles are global per-user (not Organization-
     * scoped - Teams was rejected, see ADR 0001), so this only ever fires
     * for a user picked here who has no Role at all yet - otherwise a
     * Super Admin manually onboarding an enterprise customer could hand
     * that picked "Owner" a brand-new user shell that can't even log in.
     * A missing/renamed "Owner" Role (OrganizationAccessRoleSeeder) simply
     * leaves the user role-less, exactly like accessRoleFor() elsewhere -
     * never an error, since granting no permissions is always the safe
     * failure mode here.
     */
    private function assignOwnerAccessRoleIfNone(User $owner): void
    {
        if ($owner->roles()->exists()) {
            return;
        }

        $role = Role::where('name', 'Owner')->first();

        if ($role !== null && ! $role->isSuperAdminRole()) {
            $owner->assignRole($role);
        }
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

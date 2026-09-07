<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use App\Support\OrganizationContext;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 — Organization Management. Deliberately NOT a Resource +
 * RelationManager: Filament checks attach/detach/edit abilities on a
 * RelationManager against the *related* model's policy with zero parent
 * context (vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php),
 * which is exactly how ProjectResource's UsersRelationManager ends up gated
 * by nothing but a flat "Update project" permission. Reusing that shape
 * here would conflate Organization and Project member management under the
 * same UserPolicy methods. This page instead builds its table directly
 * (Filament's documented "table outside a Resource" API) and calls
 * OrganizationPolicy explicitly from every action.
 *
 * No page permission ($permission stays null via AuthorizedPage): every
 * member may open this page (OrganizationPolicy::view()), only Owner/Admin
 * can act on it — enforced per-action below, not by hiding the page.
 */
class OrganizationSettings extends AuthorizedPage implements HasForms, HasTable
{
    use InteractsWithForms;
    use Tables\Concerns\InteractsWithTable;

    /**
     * Phase 5.2 fix (pre-existing since Phase 5): Livewire needs this
     * declared as a real public property to hydrate the form across
     * requests - Filament's own Resource pages declare the same
     * (vendor/filament/filament/src/Resources/Pages/CreateRecord.php:24).
     * Without it, InteractsWithForms's magic __get() only starts finding it
     * once something else has already made it exist, which never happened
     * here - the property never existed at all, so the very next Livewire
     * request after the first render threw "Public property [$data] not
     * found" the moment anything tried to update the name field. Confirmed
     * this was silently broken in the real component (not just a test
     * artifact): a plain `wire:model`-style property update goes through
     * the exact same PerformDataBindingUpdates middleware a test's set()
     * call does.
     */
    public $data;

    protected static ?string $navigationIcon = 'heroicon-o-office-building';

    protected static string $view = 'filament.pages.organization-settings';

    protected static ?string $slug = 'organization';

    private ?Organization $currentOrganization = null;

    /**
     * Never a cached/rehydrated Livewire property: re-resolved through
     * OrganizationContext (which re-verifies membership every call) on
     * every request, the same discipline Phase 3A/B applied to every other
     * per-record page in this panel. Memoized only for the lifetime of a
     * single request/instance, not across them.
     */
    private function organization(): ?Organization
    {
        return $this->currentOrganization ??= OrganizationContext::current(auth()->user());
    }

    public function mount(): void
    {
        abort_unless($this->organization() !== null, 404);

        $this->form->fill([
            'name' => $this->organization()->name,
        ]);
    }

    protected static function getNavigationLabel(): string
    {
        return __('Organization');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    protected static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && OrganizationContext::current(auth()->user()) !== null;
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Organization settings');
    }

    // ------------------------------------------------------------- settings

    protected function getFormSchema(): array
    {
        return [
            Section::make(__('Organization information'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn () => ! $this->organization()->isManageableBy(auth()->user())),
                ]),
        ];
    }

    public function save(): void
    {
        $organization = $this->organization();

        Gate::authorize('update', $organization);

        $data = $this->form->getState();

        $organization->update(['name' => $data['name']]);

        Notification::make()
            ->title(__('Organization updated'))
            ->success()
            ->send();
    }

    /**
     * Public on purpose: the Blade view reads this to decide whether to
     * show the Save button at all — a UX nicety only. save() itself still
     * authorizes independently, so hiding the button here changes nothing
     * about what a crafted request can or cannot do.
     */
    public function canManageOrganization(): bool
    {
        return $this->organization()->isManageableBy(auth()->user());
    }

    // -------------------------------------------------------------- members

    /**
     * Filament's table pipeline requires a plain Builder (not a Relation),
     * which means BelongsToMany::get()'s own pivot-hydration step
     * (Illuminate\Database\Eloquent\Relations\BelongsToMany::hydratePivotRelation())
     * never runs here — $record->pivot would silently be empty. Rather than
     * fight that, the role is selected explicitly under its own name
     * (member_role) and read via that, and every other place that needs a
     * row's role calls Organization::roleOf() (which does use the real
     * relation and is unaffected by this) instead of $record->pivot. This
     * was Phase 5's actual bug: the "Role" badge column had nothing to read.
     */
    protected function getTableQuery(): Builder
    {
        // select('users.*') rather than the relation's default bare '*':
        // the join means an unqualified '*' collides users.id with
        // organization_users.id, and the ambiguous result silently broke
        // record identification (Filament's getTableRecordKey() came back
        // null) once a second select was added alongside it.
        return $this->organization()->users()
            ->getQuery()
            ->select('users.*')
            ->addSelect('organization_users.role as member_role');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('Organization members');
    }

    protected function getTableHeaderActions(): array
    {
        return [
            // Phase 5.3A. Adding an EXISTING User, never an invitation (no
            // email/token infrastructure exists or is added here) - the
            // target is always resolved server-side from a submitted
            // user_id, never trusted from the rendered search results
            // alone.
            Tables\Actions\Action::make('addMember')
                ->label(__('Add member'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('Add member'))
                ->visible(fn () => $this->organization()->isManageableBy(auth()->user()))
                ->form([
                    // True server-side search (getSearchResultsUsing), not a
                    // preloaded options() array like UserOptions elsewhere in
                    // this app - the candidate pool here is every User in the
                    // platform, not a bounded project-contributor list, so
                    // preloading all of them would not scale. Only id/name/
                    // email ever leave the server - never password, tokens,
                    // or any other column.
                    Select::make('user_id')
                        ->label(__('User'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) {
                            $organization = $this->organization();

                            return User::query()
                                ->select(['id', 'name', 'email'])
                                ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%"))
                                // Excludes only membership in THIS organization
                                // - a User already belonging to a different
                                // organization (or none at all) is still a
                                // valid, selectable candidate.
                                ->whereDoesntHave('organizations', fn (Builder $query) => $query->whereKey($organization->id))
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"]);
                        })
                        ->getOptionLabelUsing(function ($value) {
                            $user = User::query()->select(['id', 'name', 'email'])->find($value);

                            return $user ? "{$user->name} ({$user->email})" : null;
                        }),
                    Select::make('role')
                        ->label(__('Role'))
                        ->required()
                        ->default(fn () => config('system.organizations.affectations.roles.default'))
                        // Same reuse-the-Policy-to-build-options approach as
                        // changeRole below: an Admin never even sees "Owner"
                        // as a choice, rather than only being blocked from it
                        // after submitting.
                        ->options(function () {
                            $organization = $this->organization();
                            $actor = auth()->user();

                            return collect(config('system.organizations.affectations.roles.list'))
                                ->filter(fn ($label, $role) => Gate::forUser($actor)
                                    ->allows('addMember', [$organization, $role]))
                                ->all();
                        }),
                ])
                ->action(function (array $data): void {
                    $organization = $this->organization();
                    $role = $data['role'];

                    // The role in $data is client-controlled state - re-checked
                    // against the allow-list, the actor's own role, and Owner
                    // protection every time, never trusting the rendered
                    // <select>'s options alone (same discipline as changeRole).
                    Gate::authorize('addMember', [$organization, $role]);

                    // The selected user is client-controlled state too - the
                    // real User is resolved from the database by id, never
                    // trusted as a name/email string, and default Eloquent
                    // querying already excludes soft-deleted users (User uses
                    // SoftDeletes) without any extra code here.
                    $target = User::query()->find($data['user_id']);

                    if ($target === null) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.user_id' => __('This user could not be found.'),
                        ]);
                    }

                    if ($organization->isAccessibleBy($target)) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.user_id' => __('This user is already a member of this organization.'),
                        ]);
                    }

                    try {
                        $organization->users()->attach($target->id, ['role' => $role]);
                    } catch (QueryException $exception) {
                        // The unique(organization_id, user_id) index (present
                        // since Phase 2) is the real backstop against a race
                        // between two concurrent "add this user" requests -
                        // the check above is a friendly fast path, this catch
                        // is what actually closes the race, exactly like
                        // CreateOrganization's duplicate-name handling.
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'mountedTableActionData.user_id' => __('This user is already a member of this organization.'),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Member added'))
                        ->success()
                        ->send();
                }),

            // Phase 5.4. Unlike addMember above, the recipient does not need
            // to already have an account here - an invitation is addressed
            // to an email, resolved to a real membership only later, by
            // App\Http\Livewire\AcceptOrganizationInvitation, and only after
            // that page independently re-verifies everything below (the
            // invitation's own validity, and that the accepting identity
            // matches the invited email) - this action's job ends at
            // creating + mailing the invitation.
            Tables\Actions\Action::make('inviteMember')
                ->label(__('Invite user'))
                ->icon('heroicon-o-mail')
                ->modalHeading(__('Invite user'))
                ->visible(fn () => $this->organization()->isManageableBy(auth()->user()))
                ->form([
                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('role')
                        ->label(__('Role'))
                        ->required()
                        ->default(fn () => config('system.organizations.affectations.roles.default'))
                        // Exact reuse of addMember's authority question: "is
                        // this actor allowed to grant this role to a new
                        // member of this organization" is the same question
                        // whether the membership is created immediately
                        // (addMember) or after an invitation is accepted -
                        // no separate/duplicated authorization logic here.
                        ->options(function () {
                            $organization = $this->organization();
                            $actor = auth()->user();

                            return collect(config('system.organizations.affectations.roles.list'))
                                ->filter(fn ($label, $role) => Gate::forUser($actor)
                                    ->allows('addMember', [$organization, $role]))
                                ->all();
                        }),
                ])
                ->action(function (array $data): void {
                    $organization = $this->organization();
                    $role = $data['role'];

                    Gate::authorize('addMember', [$organization, $role]);

                    $email = trim((string) $data['email']);

                    // Not an enumeration leak: the actor is already an
                    // Owner/Admin of THIS organization asking about ITS OWN
                    // membership/invitations, not an arbitrary requester
                    // probing the platform (the leak this brief's "Email
                    // Security" section actually warns against, which
                    // applies to the accept side, not here).
                    $existingMember = User::where('email', $email)->first();
                    if ($existingMember !== null && $organization->isAccessibleBy($existingMember)) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.email' => __('This user is already a member of this organization.'),
                        ]);
                    }

                    if ($organization->invitations()->pending()->where('email', $email)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.email' => __('An invitation for this email is already pending.'),
                        ]);
                    }

                    $plainToken = OrganizationInvitation::generateToken();

                    $invitation = OrganizationInvitation::create([
                        'organization_id' => $organization->id,
                        'email' => $email,
                        'role' => $role,
                        'token_hash' => OrganizationInvitation::hashToken($plainToken),
                        'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
                        'created_by' => auth()->id(),
                    ]);

                    // Notification::route(), not $existingMember->notify():
                    // the recipient may not have an account at all yet, and
                    // even when they do, an invitation is addressed to the
                    // email it names, never to whichever account currently
                    // happens to hold that address.
                    NotificationFacade::route('mail', $email)
                        ->notify(new OrganizationInvitationCreated($invitation, $plainToken));

                    Notification::make()
                        ->title(__('Invitation sent'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Phase 5.4. Rendered as a plain Blade list (organization-settings.blade.php),
     * not a second Filament table - a page built on InteractsWithTable only
     * ever drives one table (the members list above), and a second,
     * independent listing here does not need the sorting/searching/pagination
     * machinery a full Filament table brings.
     */
    public function pendingInvitations(): Collection
    {
        return $this->organization()->invitations()
            ->pending()
            ->with('inviter')
            ->latest()
            ->get();
    }

    /**
     * Cross-organization revocation is closed by construction, not just by
     * the Gate check below: the lookup itself is scoped to
     * $this->organization()->invitations() (the actor's OWN current
     * organization), so an invitation id belonging to a different
     * organization is never found here at all, regardless of what the
     * Gate would have said about it.
     */
    public function revokeInvitation(int $invitationId): void
    {
        $organization = $this->organization();

        Gate::authorize('revokeInvitation', $organization);

        $invitation = $organization->invitations()->whereKey($invitationId)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return;
        }

        OrganizationInvitation::whereKey($invitation->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Notification::make()
            ->title(__('Invitation revoked'))
            ->success()
            ->send();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('User full name'))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('email')
                ->label(__('Email'))
                ->searchable(),

            Tables\Columns\BadgeColumn::make('member_role')
                ->label(__('Role'))
                ->enum(config('system.organizations.affectations.roles.list'))
                ->colors(config('system.organizations.affectations.roles.colors'))
                ->tooltip(function (User $record) {
                    $organization = $this->organization();

                    return $organization->roleOf($record) === 'owner' && $organization->ownerCount() <= 1
                        ? __('Sole Owner — cannot be removed or demoted')
                        : null;
                })
                ->searchable()
                ->sortable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\Action::make('changeRole')
                ->label(__('Change role'))
                ->icon('heroicon-o-pencil')
                ->modalHeading(__('Change member role'))
                // A target that is an Owner can only ever be touched by
                // another Owner (OrganizationPolicy::updateMemberRole) -
                // excluded here too so the modal never opens onto a form
                // with zero valid options for an Admin actor.
                ->visible(fn (User $record) => $this->organization()->isManageableBy(auth()->user())
                    && $record->id !== auth()->id()
                    && ($this->organization()->roleOf($record) !== 'owner' || $this->organization()->isOwnedBy(auth()->user())))
                ->form([
                    Placeholder::make('target_name')
                        ->label(__('User full name'))
                        ->content(fn (User $record) => $record->name),
                    Placeholder::make('target_email')
                        ->label(__('Email'))
                        ->content(fn (User $record) => $record->email),
                    Select::make('role')
                        ->label(__('Role'))
                        ->required()
                        // Reuses the exact same Policy method the action
                        // itself enforces below - a role this actor could
                        // not actually set for this target (e.g. demoting
                        // the sole Owner) never even appears as an option,
                        // rather than duplicating that logic here.
                        ->options(function (User $record) {
                            $organization = $this->organization();
                            $actor = auth()->user();

                            return collect(config('system.organizations.affectations.roles.list'))
                                ->filter(fn ($label, $role) => Gate::forUser($actor)
                                    ->allows('updateMemberRole', [$organization, $record, $role]))
                                ->all();
                        })
                        ->default(fn (User $record) => $this->organization()->roleOf($record)),
                ])
                ->action(function (User $record, array $data): void {
                    $organization = $this->organization();

                    // The role in $data is Livewire/Filament state the
                    // client controls — Gate::authorize re-checks it against
                    // the allow-list, the actor's own role, and Owner
                    // protection every time, never trusting the rendered
                    // <select>'s options alone.
                    Gate::authorize('updateMemberRole', [$organization, $record, $data['role']]);

                    $organization->users()->updateExistingPivot($record->id, ['role' => $data['role']]);

                    Notification::make()
                        ->title(__('Role updated'))
                        ->success()
                        ->send();
                }),

            Tables\Actions\Action::make('removeMember')
                ->label(__('Remove'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Remove member?'))
                ->modalSubheading(fn (User $record) => __('Are you sure you want to remove :name from :organization?', [
                    'name' => $record->name,
                    'organization' => $this->organization()->name,
                ]))
                ->modalButton(__('Remove'))
                // Exact reuse of the real Policy check (not just the coarse
                // "can manage" test): a target that removeMember() would
                // refuse (the sole Owner, or an Owner acted on by an Admin)
                // never shows a button that would only fail on click.
                ->visible(fn (User $record) => $record->id !== auth()->id()
                    && Gate::forUser(auth()->user())->allows('removeMember', [$this->organization(), $record]))
                ->action(function (User $record): void {
                    $organization = $this->organization();

                    Gate::authorize('removeMember', [$organization, $record]);

                    $organization->users()->detach($record->id);

                    Notification::make()
                        ->title(__('Member removed'))
                        ->success()
                        ->send();
                }),
        ];
    }
}

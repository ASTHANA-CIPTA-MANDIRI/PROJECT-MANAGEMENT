<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use App\Notifications\OrganizationMemberAdded;
use App\Support\OrganizationContext;
use Filament\Forms\Components\Grid;
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

    /**
     * Phase 5.4.1 — unifies what used to be two separate actions (Phase
     * 5.3A's "Add member" by user_id search, Phase 5.4's "Invite user" by
     * email) into the single "+ Tambah Anggota" control the product wants:
     * Owner/Admin only ever supplies an email and a role, and the server
     * decides which of the two paths applies — never the UI, and never by
     * asking the actor to know the difference.
     *
     * Owner/Admin NEVER sets a password here, for either path: an existing
     * User keeps their own password untouched (only a new organization_users
     * row is written), and a brand-new User only ever gets one by going
     * through the real, unmodified registration form themselves after
     * opening the invitation link — this action never creates a User row at
     * all in the new-recipient case.
     */
    protected function getTableHeaderActions(): array
    {
        return [
            Tables\Actions\Action::make('addMember')
                ->label(__('Add member'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('Add member'))
                ->visible(fn () => $this->organization()->isManageableBy(auth()->user()))
                ->form([
                    // Phase 5.4.2. Required for both branches below, but
                    // only ever actually used for the new-recipient one
                    // (stored on the invitation, later applied to the
                    // brand-new account at acceptance) - an existing user's
                    // own users.name is never touched by this value, no
                    // matter what is typed here (see addExistingUser()).
                    TextInput::make('name')
                        ->label(__('Full name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Grid::make(2)->schema([
                        Select::make('role')
                            ->label(__('Role'))
                            ->required()
                            ->reactive()
                            ->default(fn () => config('system.organizations.affectations.roles.default'))
                            // Owner is never one of these two options, for
                            // anyone - OrganizationPolicy::addMember() itself
                            // rejects it unconditionally now (Phase 5.4.1),
                            // ownership assignment is a distinct, not-yet-built
                            // feature. ->only() reads the two labels from the
                            // existing config rather than hard-coding them here
                            // a second time.
                            ->options(function () {
                                $organization = $this->organization();
                                $actor = auth()->user();

                                return collect(config('system.organizations.affectations.roles.list'))
                                    ->only(['admin', 'member'])
                                    ->filter(fn ($label, $role) => Gate::forUser($actor)
                                        ->allows('addMember', [$organization, $role]))
                                    ->all();
                            })
                            // Keeps the two fields feeling like one choice
                            // instead of two unrelated dropdowns: picking a
                            // Role auto-fills the matching seeded Access role
                            // (OrganizationAccessRoleSeeder) below, editable
                            // afterwards like any other default.
                            ->afterStateUpdated(fn ($state, callable $set) => $set(
                                'access_role_id',
                                $this->accessRoleFor($state)?->id
                            )),
                        Select::make('access_role_id')
                            ->label(__('Access role'))
                            ->helperText(__('Which feature permissions this member gets, managed by a Super Admin under Roles. Follows the Role above by default.'))
                            ->default(fn () => $this->accessRoleFor(config('system.organizations.affectations.roles.default'))?->id)
                            // Every Role except the platform's own Super Admin
                            // one - never offered here at all, so an Owner/Admin
                            // (who may not even hold that permission themselves)
                            // can never hand it out through an invitation. This
                            // is a UX nicety only; the action below re-checks
                            // the submitted id against the exact same exclusion,
                            // never trusting this options list alone.
                            ->options(fn () => Role::query()
                                ->get()
                                ->reject(fn (Role $role) => $role->isSuperAdminRole())
                                ->pluck('name', 'id')),
                    ]),
                ])
                ->action(function (array $data): void {
                    $organization = $this->organization();
                    $role = $data['role'];

                    // The role in $data is client-controlled state -
                    // re-checked against the allow-list (admin/member only,
                    // never owner) and the actor's own manage authority
                    // every time, never trusting the rendered <select>'s
                    // options alone.
                    Gate::authorize('addMember', [$organization, $role]);

                    $accessRole = $this->resolveAccessRole($data['access_role_id'] ?? null);

                    $name = trim((string) $data['name']);
                    $email = trim((string) $data['email']);

                    if ($name === '') {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('The full name must not be blank.'),
                        ]);
                    }

                    // The default Eloquent query already excludes
                    // soft-deleted users (User uses SoftDeletes) without any
                    // extra code here - a soft-deleted account's email
                    // resolves as "not found" below, exactly like a genuine
                    // new recipient, rather than being silently restored or
                    // attached.
                    $existingUser = User::where('email', $email)->first();

                    if ($existingUser !== null) {
                        // $name is deliberately never passed here - see
                        // addExistingUser()'s own docblock.
                        $this->addExistingUser($organization, $existingUser, $role, $accessRole);

                        return;
                    }

                    $this->inviteNewRecipient($organization, $name, $email, $role, $accessRole);
                }),
        ];
    }

    /**
     * Maps an Organization role (owner/admin/member) to the seeded Access
     * role of the same name (OrganizationAccessRoleSeeder: "Owner"/"Admin"/
     * "Member") - the auto-fill default for access_role_id whenever the
     * Role field changes, so picking one Role reads as a single choice
     * instead of two unrelated dropdowns. Purely a UI convenience: null
     * (no seeded match, or someone later renamed/removed that Role) just
     * leaves access_role_id for the actor to pick by hand, never an error.
     */
    private function accessRoleFor(?string $organizationRole): ?Role
    {
        if ($organizationRole === null) {
            return null;
        }

        $role = Role::where('name', ucfirst($organizationRole))->first();

        return $role !== null && ! $role->isSuperAdminRole() ? $role : null;
    }

    /**
     * The submitted access_role_id is client-controlled state - re-resolved
     * through a real query (never trusted as a bare id) and re-checked
     * against the exact same Super-Admin exclusion the picker's own
     * ->options() applies, so a crafted request cannot hand out that role
     * just because the Select happened not to render it. A blank/invalid
     * value (id belongs to no Role, or was the Super Admin one) silently
     * resolves to null - "do not change the recipient's access role" -
     * rather than failing the whole add-member action over it.
     */
    private function resolveAccessRole(?string $accessRoleId): ?Role
    {
        if ($accessRoleId === null || $accessRoleId === '') {
            return null;
        }

        $role = Role::find($accessRoleId);

        if ($role === null || $role->isSuperAdminRole()) {
            return null;
        }

        return $role;
    }

    /**
     * The existing-user branch: attach immediately, no password of any kind
     * touched or created, notify by email. Split out from the action
     * closure above only for readability - both branches still run inside
     * the same authorized action.
     *
     * Phase 5.4.2: deliberately takes no $name parameter at all - whatever
     * Owner/Admin typed in the "Full name" field is discarded for this
     * branch, never written to $existingUser->name. An existing account's
     * name belongs to that account, not to whoever happens to type
     * something in this form.
     *
     * $accessRole is only ever applied when $existingUser holds no Role at
     * all yet - an existing account may already be active (with an
     * established Role) in another Organization, and Spatie Roles are
     * still global per-user (see [[subscription-model-direction]] /
     * ADR 0001's Teams rejection - this codebase deliberately does not use
     * Spatie Teams), so silently overwriting it here would change what
     * that person can do everywhere else they already work, not just in
     * this Organization. A user with zero Roles has nothing to protect.
     */
    private function addExistingUser(Organization $organization, User $existingUser, string $role, ?Role $accessRole): void
    {
        if ($organization->isAccessibleBy($existingUser)) {
            throw ValidationException::withMessages([
                'mountedTableActionData.email' => __('This user is already a member of this organization.'),
            ]);
        }

        try {
            $organization->users()->attach($existingUser->id, ['role' => $role]);
        } catch (QueryException $exception) {
            // The unique(organization_id, user_id) index (present since
            // Phase 2) is the real backstop against a race between two
            // concurrent "add this user" requests - the check above is a
            // friendly fast path, this catch is what actually closes the
            // race, exactly like CreateOrganization's duplicate-name
            // handling.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'mountedTableActionData.email' => __('This user is already a member of this organization.'),
            ]);
        }

        if ($accessRole !== null && ! $existingUser->roles()->exists()) {
            $existingUser->assignRole($accessRole);
        }

        $existingUser->notify(new OrganizationMemberAdded($organization, $role));

        Notification::make()
            ->title(__('Member added'))
            ->success()
            ->send();
    }

    /**
     * The new-recipient branch: no User row is ever created here - only an
     * invitation, exactly Phase 5.4's existing, unmodified mechanism. The
     * recipient creates their own account (and their own password) later,
     * through the ordinary, untouched registration form, then verifies
     * their email, then opens this same link again to accept.
     *
     * Phase 5.4.2: $name is carried on the invitation itself (never
     * assumed, never re-derived) so App\Http\Livewire\AcceptOrganizationInvitation
     * can apply it to the brand-new account at the moment membership is
     * actually created - see that class for exactly when and why.
     *
     * $accessRole is carried the same way, for the same reason - see
     * AcceptOrganizationInvitation::accept() for why it is safe to apply
     * unconditionally there (unlike addExistingUser() above), even though
     * self-registration in between already assigned the platform's default
     * Role.
     */
    private function inviteNewRecipient(Organization $organization, string $name, string $email, string $role, ?Role $accessRole): void
    {
        if ($organization->invitations()->pending()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'mountedTableActionData.email' => __('An invitation for this email is already pending.'),
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'access_role_id' => $accessRole?->id,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
            'created_by' => auth()->id(),
        ]);

        // Notification::route(), not a User::notify(): there is no User row
        // for this recipient yet, by definition of reaching this branch at
        // all.
        NotificationFacade::route('mail', $email)
            ->notify(new OrganizationInvitationCreated($invitation, $plainToken));

        Notification::make()
            ->title(__('Invitation sent'))
            ->success()
            ->send();
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
                    Grid::make(2)->schema([
                        Select::make('role')
                            ->label(__('Role'))
                            ->required()
                            ->reactive()
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
                            ->default(fn (User $record) => $this->organization()->roleOf($record))
                            // Same "feels like one choice" auto-fill as
                            // addMember's Role field above.
                            ->afterStateUpdated(fn ($state, callable $set) => $set(
                                'access_role_id',
                                $this->accessRoleFor($state)?->id
                            )),
                        Select::make('access_role_id')
                            ->label(__('Access role'))
                            ->helperText(__('Which feature permissions this member gets, managed by a Super Admin under Roles. Follows the Role above by default.'))
                            // Whatever Role this member already holds takes
                            // priority over guessing from their Organization
                            // role - an Owner/Admin who already fine-tuned
                            // someone's access manually should not have that
                            // silently reset just by opening this modal.
                            ->default(fn (User $record) => $record->roles()->first()?->id
                                ?? $this->accessRoleFor($this->organization()->roleOf($record))?->id)
                            ->options(fn () => Role::query()
                                ->get()
                                ->reject(fn (Role $role) => $role->isSuperAdminRole())
                                ->pluck('name', 'id')),
                    ]),
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

                    $accessRole = $this->resolveAccessRole($data['access_role_id'] ?? null);

                    // Unlike addExistingUser()'s never-overwrite-an-existing-
                    // Role guard, this action's entire purpose is explicitly
                    // setting this member's role - syncRoles() (replace) is
                    // the correct, intended effect here, not a landmine.
                    if ($accessRole !== null) {
                        $record->syncRoles([$accessRole]);
                    }

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

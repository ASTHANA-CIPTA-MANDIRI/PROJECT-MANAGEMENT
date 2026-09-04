<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\User;
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
use Illuminate\Support\Facades\Gate;

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
            // No invitation/add-member flow exists anywhere in this app yet
            // (no email/token infrastructure) - Step 9 explicitly forbids
            // building one just for this page. A disabled placeholder keeps
            // the target layout without pretending the feature works;
            // wiring it up is Future Phase.
            Tables\Actions\Action::make('addMember')
                ->label(__('Add member'))
                ->icon('heroicon-o-plus')
                ->disabled()
                ->tooltip(__('Coming soon'))
                ->visible(fn () => $this->organization()->isManageableBy(auth()->user())),
        ];
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

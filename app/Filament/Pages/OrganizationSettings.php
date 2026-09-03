<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Filament\Forms\Components\Card;
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
        return $this->organization()->name;
    }

    // ------------------------------------------------------------- settings

    protected function getFormSchema(): array
    {
        return [
            Card::make()
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

    // -------------------------------------------------------------- members

    protected function getTableQuery(): Builder
    {
        return $this->organization()->users()->getQuery();
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

            Tables\Columns\BadgeColumn::make('pivot.role')
                ->label(__('Role'))
                ->enum(config('system.organizations.affectations.roles.list'))
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
                ->visible(fn (User $record) => $this->organization()->isManageableBy(auth()->user())
                    && $record->id !== auth()->id())
                ->form([
                    Select::make('role')
                        ->label(__('Role'))
                        ->required()
                        ->options(fn () => config('system.organizations.affectations.roles.list'))
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
                ->visible(fn (User $record) => $this->organization()->isManageableBy(auth()->user())
                    && $record->id !== auth()->id())
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

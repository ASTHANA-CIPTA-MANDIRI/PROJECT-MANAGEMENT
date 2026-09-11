<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Support\OrganizationContext;
use App\Support\OrganizationDefaults;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5.2 — Create Organization + Initial Owner. Reached from the
 * Organization Switcher (Phase 3C) — deliberately not in the main sidebar
 * navigation, to avoid cluttering it now that OrganizationSettings already
 * occupies "Organization" there.
 *
 * No page permission ($permission stays null via AuthorizedPage):
 * OrganizationPolicy::create() is the real, testable gate, called
 * explicitly below — the same discipline OrganizationSettings already
 * applies to its own actions.
 */
class CreateOrganization extends AuthorizedPage implements HasForms
{
    use InteractsWithForms;

    /**
     * Livewire needs this declared as a real public property to hydrate
     * across requests - Filament's own Resource pages declare the same
     * (vendor/filament/filament/src/Resources/Pages/CreateRecord.php:24).
     * Without it, the form works on the very first render but the *next*
     * Livewire request (any subsequent interaction at all) throws
     * "Public property [$data] not found", since InteractsWithForms itself
     * never declares this property - it only assumes something already has.
     */
    public $data;

    protected static string $view = 'filament.pages.create-organization';

    protected static ?string $slug = 'create-organization';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        Gate::authorize('create', Organization::class);

        $this->form->fill();
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Create organization');
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make(__('Organization information'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->string()
                        ->maxLength(255),
                ]),
        ];
    }

    /**
     * Create + initial-owner membership are one atomic unit: if the
     * membership insert ever fails, the organization must not be left
     * behind orphaned (no owner, unreachable via OrganizationContext,
     * invisible to everyone). The creator is always auth()->id() — there is
     * no field anywhere in this form for a client to name someone else, and
     * the role is always the literal 'owner', never a value read from the
     * request.
     */
    public function create(): void
    {
        Gate::authorize('create', Organization::class);

        $name = trim((string) $this->form->getState()['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'data.name' => __('The organization name must not be blank.'),
            ]);
        }

        if (Organization::where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'data.name' => __('This organization name is already taken.'),
            ]);
        }

        try {
            $organization = DB::transaction(function () use ($name) {
                $organization = Organization::create(['name' => $name]);
                $organization->users()->attach(auth()->id(), ['role' => 'owner']);
                OrganizationDefaults::seed($organization);

                return $organization;
            });
        } catch (QueryException $exception) {
            // organizations.name is unique at the database level too
            // (2026_09_02_000001_create_organizations_table.php) - the
            // final backstop against a race with the check above. SQLSTATE
            // 23000 covers unique/foreign-key violations on both the MySQL
            // connection this app runs on and the SQLite one its tests do;
            // anything else is a real failure and must not be swallowed.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'data.name' => __('This organization name is already taken.'),
            ]);
        }

        OrganizationContext::switch(auth()->user(), $organization->id);

        Notification::make()
            ->title(__('Organization created'))
            ->success()
            ->send();

        $this->redirect(route('filament.pages.organization'));
    }
}

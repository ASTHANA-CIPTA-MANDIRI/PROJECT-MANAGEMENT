<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Actions\Action;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ManageGeneralSettings extends SettingsPage
{
    // SettingsPage is not a Filament\Pages\Page subclass we own, so the shared
    // concern is applied directly instead of extending AuthorizedPage.
    use AuthorizesPageAccess;

    /**
     * Repointing the Super Admin role decides who User::isSuperAdmin() treats
     * as the main administrator, so it is gated behind its own permission: a
     * plain settings manager must not be able to promote a role they already
     * hold.
     */
    public const SUPER_ADMIN_PERMISSION = 'Manage super admin settings';

    protected static ?string $permission = 'Manage general settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static string $settings = GeneralSettings::class;

    protected function getHeading(): string|Htmlable
    {
        return __('Manage general settings');
    }

    protected static function getNavigationLabel(): string
    {
        return __('General');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    protected function getFormSchema(): array
    {
        return [
            Card::make()
                ->schema([
                    Grid::make(3)
                        ->schema([
                            FileUpload::make('site_logo')
                                ->label(__('Site logo'))
                                ->helperText(__('This is the platform logo (e.g. Used in site favicon)'))
                                ->image()
                                ->columnSpan(1)
                                ->maxSize(config('system.max_file_size')),

                            Grid::make(1)
                                ->columnSpan(2)
                                ->schema([
                                    TextInput::make('site_name')
                                        ->label(__('Site name'))
                                        ->helperText(__('This is the platform name'))
                                        ->default(fn () => config('app.name'))
                                        ->required(),

                                    Toggle::make('enable_registration')
                                        ->label(__('Enable registration?'))
                                        ->helperText(__('If enabled, any user can create an account in this platform. But an administration need to give them permissions.')),

                                    Toggle::make('enable_social_login')
                                        ->label(__('Enable social login?'))
                                        ->helperText(__('If enabled, configured users can login via their social accounts.')),

                                    Toggle::make('enable_login_form')
                                        ->label(__('Enable form login?'))
                                        ->helperText(__('If enabled, a login form will be visible on the login page.')),

                                    Select::make('site_language')
                                        ->label(__('Site language'))
                                        ->helperText(__('The language used by the platform.'))
                                        ->searchable()
                                        ->options($this->getLanguages()),

                                    Select::make('default_role')
                                        ->label(__('Default role'))
                                        ->helperText(__('The platform default role (assigned to newly registered users).'))
                                        ->searchable()
                                        ->options(Role::all()->pluck('name', 'id')->toArray()),

                                    Select::make('super_admin_role')
                                        ->label(__('Super Admin role'))
                                        ->helperText(__('Role treated as the main administrator (full access, 2FA policy). This role cannot be deleted while selected.'))
                                        ->searchable()
                                        ->reactive()
                                        ->rules(['nullable', 'exists:roles,id'])
                                        ->visible(fn () => static::userCanManageSuperAdminRole())
                                        ->options(Role::all()->pluck('name', 'id')->toArray()),

                                    Placeholder::make('super_admin_status')
                                        ->label(__('Super Admin summary'))
                                        ->content(fn (callable $get) => $this->superAdminSummary(
                                            static::userCanManageSuperAdminRole()
                                                ? $get('super_admin_role')
                                                : app(GeneralSettings::class)->super_admin_role
                                        )),
                                ]),
                        ]),
                ]),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('Save'));
    }

    public static function userCanManageSuperAdminRole(): bool
    {
        return (bool) auth()->user()?->can(self::SUPER_ADMIN_PERMISSION);
    }

    /**
     * Last line of defence for the two privilege-escalation paths this form
     * exposes. A crafted Livewire payload that smuggles `super_admin_role` into
     * the form state is overwritten with the stored value, and the default role
     * — handed to every new registrant — may not be pointed at the Super Admin
     * role, which would otherwise turn "enable registration" into a self-service
     * admin account.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (static::userCanManageSuperAdminRole()) {
            return $data;
        }

        $data['super_admin_role'] = app(GeneralSettings::class)->super_admin_role;

        if (filled($data['default_role'] ?? null) && Role::find($data['default_role'])?->isSuperAdminRole()) {
            throw ValidationException::withMessages([
                'data.default_role' => __('You are not allowed to make the Super Admin role the default role.'),
            ]);
        }

        return $data;
    }

    private function getLanguages(): array
    {
        $languages = config('system.locales.list');
        asort($languages);

        return $languages;
    }

    /**
     * A friendly, live summary of who the current Super Admins are so the
     * setting's effect is never silent.
     */
    private function superAdminSummary($roleId): HtmlString
    {
        if ($roleId && $role = Role::find($roleId)) {
            $count = $role->users()->count();

            return new HtmlString(
                '<span style="color:#15803d">✓ <strong>'.e($role->name).'</strong></span> — '
                .$count.' '.__('user(s) currently hold this role')
            );
        }

        // No (valid) selection → the app falls back to a role named "Super Admin".
        $count = User::superAdmins()->count();

        return new HtmlString(
            '<span style="color:#b45309">⚠ '
            .__('No role selected — the app falls back to a role named “Super Admin”')
            .'</span> ('.$count.' '.__('user(s)').')'
        );
    }
}

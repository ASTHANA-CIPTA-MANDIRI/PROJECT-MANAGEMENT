<?php

namespace App\Providers;

use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\BulkDeleteAuthorizer;
use App\Support\BulkRestoreAuthorizer;
use App\Support\UserCountsMemo;
use DutchCodingCompany\FilamentSocialite\FilamentSocialite;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use JeffGreco13\FilamentBreezy\FilamentBreezy;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Route actions are resolved through the container, so binding the
        // packaged socialite controller to our subclass makes the OAuth
        // callback enforce two-factor authentication without having to
        // redeclare the package's routes (and depend on registration order).
        $this->app->bind(
            \DutchCodingCompany\FilamentSocialite\Http\Controllers\SocialiteLoginController::class,
            \App\Http\Controllers\Auth\SocialiteLoginController::class,
        );

        $this->app->singleton(UserCountsMemo::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Configure application
        $this->configureApp();

        // Hide social login buttons for providers missing OAuth credentials,
        // so a stale "enable_social_login" toggle can't send users into an
        // OAuth redirect with a blank client_id (Google/GitHub reject that
        // with "invalid_request: Missing required parameter: client_id").
        $this->configureSocialiteProviders();

        // Enforce a strong password policy on registration/profile/reset
        $this->configurePasswordPolicy();

        // Render the Organization switcher (Phase 3C) near the top of every
        // Filament panel page's sidebar.
        $this->configureOrganizationSwitcher();
        $this->configureAlertTexts();
        $this->configureProfileDisplay();

        // Monitor database queries in local development. Writes to a dedicated
        // storage/logs/query.log channel; never active in testing/production.
        if ($this->app->environment('local')) {
            DB::listen(function ($query) {
                Log::channel('query')->debug($query->sql, [
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                ]);
            });
        }

        // Register custom Filament theme
        Filament::serving(function () {
            Filament::registerTheme(
                app(Vite::class)('resources/css/filament.scss'),
            );
        });

        // Tippy's tooltip styles are compiled into the theme above (from the
        // local `tippy.js` npm package), so no third-party stylesheet is loaded
        // at runtime.

        // Register scripts
        try {
            Filament::registerScripts([
                app(Vite::class)('resources/js/filament.js'),
            ]);
        } catch (\Exception $e) {
            // Manifest not built yet!
        }

        // Add custom meta (favicon)
        Filament::pushMeta([
            new HtmlString('<link rel="icon"
                                       type="image/x-icon"
                                       href="'.config('app.logo').'">'),
        ]);

        // Every bulk delete — in every resource and relation manager, present
        // and future — is filtered by the model's policy. Configured centrally
        // because the gap is in Filament's own action, not in any one resource.
        Tables\Actions\DeleteBulkAction::configureUsing(function (Tables\Actions\DeleteBulkAction $action): void {
            $action->using(static function (EloquentCollection $records): void {
                $denied = 0;

                $records->each(function (Model $record) use (&$denied): void {
                    if (! BulkDeleteAuthorizer::allows($record)) {
                        $denied++;

                        return;
                    }

                    $record->delete();
                });

                if ($denied > 0) {
                    Notification::make()
                        ->warning()
                        ->title(__('Some records were not deleted'))
                        ->body(__(':count record(s) you are not allowed to delete were skipped.', [
                            'count' => $denied,
                        ]))
                        ->send();
                }
            });
        });

        // Every bulk restore — in every resource and relation manager, present
        // and future — is filtered by the model's policy. Mirrors the
        // DeleteBulkAction configuration above: RestoreBulkAction only checks
        // `restoreAny()`, which can be more permissive than `restore()` (e.g.
        // UserPolicy::restore refuses to restore a Super Admin account).
        Tables\Actions\RestoreBulkAction::configureUsing(function (Tables\Actions\RestoreBulkAction $action): void {
            $action->using(static function (EloquentCollection $records): void {
                $denied = 0;

                $records->each(function (Model $record) use (&$denied): void {
                    if (! BulkRestoreAuthorizer::allows($record)) {
                        $denied++;

                        return;
                    }

                    // RestoreBulkAction is only ever wired to soft-deletable
                    // resources, but the closure signature here is typed
                    // against the generic Eloquent Model (Filament/Larastan
                    // have no way to narrow it further), so restore() reads
                    // as undefined to static analysis despite always being
                    // present at runtime.
                    // @phpstan-ignore-next-line method.notFound
                    $record->restore();
                });

                if ($denied > 0) {
                    Notification::make()
                        ->warning()
                        ->title(__('Some records were not restored'))
                        ->body(__(':count record(s) you are not allowed to restore were skipped.', [
                            'count' => $denied,
                        ]))
                        ->send();
                }
            });
        });

        // Register navigation groups
        Filament::registerNavigationGroups([
            __('Management'),
            __('Referential'),
            __('Security'),
            __('Settings'),
        ]);

        // Force HTTPS over HTTP
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Social login (Google/GitHub) creates its own users. Mark them as
        // "social" so the User model does not treat them as admin-created
        // "db" accounts (which would set a creation token and send the
        // account-validation email). Their e-mail is already verified by the
        // provider.
        app(FilamentSocialite::class)->setCreateUserCallback(function (SocialiteUserContract $oauthUser) {
            return User::create([
                'name' => $oauthUser->getName()
                    ?? $oauthUser->getNickname()
                    ?? $oauthUser->getEmail(),
                'email' => $oauthUser->getEmail(),
                'type' => 'social',
                'email_verified_at' => now(),
            ]);
        });
    }

    /**
     * A single strong password policy for every password entry point
     * (registration, My Profile, password reset).
     *
     * Set here rather than in config because config is cached in production and
     * cannot hold a Password rule object; the HaveIBeenPwned breach check runs
     * only in production so tests and offline dev don't depend on the API.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Breezy sets its rules from (string) config at boot; override them once
        // the app is fully booted so all its forms use the policy above.
        $this->app->booted(function () {
            FilamentBreezy::setPasswordRules([Password::defaults()]);
        });
    }

    /**
     * A render hook (a Blade injection point), not Filament's Tenancy
     * feature — ADR 0001 deliberately left Tenancy off, and this doesn't
     * touch that decision. `sidebar.start` is the slot Filament's own
     * sidebar template renders first, right before the navigation groups.
     */
    private function configureOrganizationSwitcher(): void
    {
        Filament::registerRenderHook(
            'sidebar.start',
            fn (): string => Blade::render("@livewire('organization-switcher')")
        );
    }

    /**
     * Translated default texts for the SweetAlert2 helper
     * (window.RencanakanAlert in resources/js/filament.js). Rendered through
     * __() so Indonesian/English follow the app locale; JSON-encoded with the
     * hex flags so a translation can never break out of the script tag.
     */
    private function configureAlertTexts(): void
    {
        Filament::registerRenderHook('scripts.start', function (): HtmlString {
            $texts = [
                'title' => __('Are you sure?'),
                'confirm' => __('Yes, continue'),
                'cancel' => __('No, cancel'),
                'newBadge' => __('new'),
                'rowsPerPage' => __('Rows per page'),
                'of' => __('of'),
                'disableTwoFactor' => __('Disable two-factor authentication? Your account will be less protected.'),
            ];

            return new HtmlString(
                '<script>window.RencanakanAlertTexts = '
                .json_encode($texts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
                .';</script>'
            );
        });
    }

    /**
     * Presentation only: a profile card pinned to the bottom of the sidebar
     * (with logout) and the name/role next to the topbar avatar. Reuses
     * Filament's own user-menu items, so the account and logout URLs stay
     * whatever the panel already configures.
     */
    private function configureProfileDisplay(): void
    {
        $render = function (string $template, array $extra = []): string {
            $user = Filament::auth()->user();

            if (! $user) {
                return '';
            }

            $items = Filament::getUserMenuItems();

            return Blade::render($template, [
                'name' => Filament::getUserName($user),
                'avatar' => Filament::getUserAvatarUrl($user),
                'role' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->first() : null,
                'accountUrl' => ($items['account'] ?? null)?->getUrl(),
                'accountLabel' => ($items['account'] ?? null)?->getLabel() ?? __('Profile'),
                'logoutUrl' => ($items['logout'] ?? null)?->getUrl() ?? route('filament.auth.logout'),
                'email' => $user->email,
                'tasksUrl' => $user->can('viewAny', \App\Models\Ticket::class) ? route('filament.resources.tickets.index') : null,
                'inboxUrl' => route('filament.pages.inbox'),
            ] + $extra);
        };

        Filament::registerRenderHook('sidebar.end', fn (): string => $render(<<<'BLADE'
            <div
                class="sidebar-profile-card"
                x-data="{ open: false }"
                x-on:click.outside="open = false"
                x-on:keydown.escape.window="open = false"
            >
                {{-- Sidebar expanded: profile link + logout icon --}}
                <div class="sidebar-profile-expanded">
                    <a @if ($accountUrl) href="{{ $accountUrl }}" @endif class="sidebar-profile-link" title="{{ $accountLabel }}">
                        <img src="{{ $avatar }}" alt="{{ $name }}" class="sidebar-profile-avatar">
                        <span class="sidebar-profile-text">
                            <span class="sidebar-profile-name">{{ $name }}</span>
                            @if ($role)<span class="sidebar-profile-role">{{ $role }}</span>@endif
                        </span>
                    </a>
                    <form method="POST" action="{{ $logoutUrl }}">
                        @csrf
                        <button type="submit" class="sidebar-profile-logout" title="{{ __('filament::layout.buttons.logout.label') }}" aria-label="{{ __('filament::layout.buttons.logout.label') }}">
                            <x-heroicon-o-logout class="w-5 h-5" />
                        </button>
                    </form>
                </div>

                {{-- Sidebar collapsed: avatar only, click for Profile / Logout --}}
                <div class="sidebar-profile-collapsed">
                    <button type="button" class="sidebar-profile-trigger" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" title="{{ $name }}">
                        <img src="{{ $avatar }}" alt="{{ $name }}" class="sidebar-profile-avatar">
                    </button>
                    <div class="sidebar-profile-menu" x-show="open" x-cloak x-transition.opacity>
                        @if ($accountUrl)
                            <a href="{{ $accountUrl }}" class="sidebar-profile-menu-item" title="{{ $accountLabel }}">
                                <x-heroicon-o-user-circle class="w-5 h-5 shrink-0" />
                            </a>
                        @endif
                        <form method="POST" action="{{ $logoutUrl }}">
                            @csrf
                            <button type="submit" class="sidebar-profile-menu-item" title="{{ __('filament::layout.buttons.logout.label') }}">
                                <x-heroicon-o-logout class="w-5 h-5 shrink-0" />
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            BLADE));

        // Top-right user menu: a profile card (avatar, name, role, e-mail), three
        // shortcuts and a Log out button. It is rendered inside Filament's own
        // dropdown, ahead of its default list, which CSS hides (the Log out here
        // posts to the same route, so nothing about authentication changes).
        Filament::registerRenderHook('user-menu.account.before', fn (): string => $render(<<<'BLADE'
            <div class="rk-um">
                <h3 class="rk-um__title">{{ __('User profile') }}</h3>

                <div class="rk-um__user">
                    <img src="{{ $avatar }}" alt="{{ $name }}" class="rk-um__avatar">
                    <div class="rk-um__who">
                        <b>{{ $name }}</b>
                        @if ($role)<span>{{ $role }}</span>@endif
                        <span class="rk-um__mail"><x-heroicon-o-mail class="h-4 w-4" /> {{ $email }}</span>
                    </div>
                </div>

                <nav class="rk-um__items">
                    @if ($accountUrl)
                        <a href="{{ $accountUrl }}" class="rk-um__item">
                            <span class="rk-um__tile rk-um__tile--blue"><x-heroicon-o-user-circle class="h-6 w-6" /></span>
                            <span><b>{{ __('My profile') }}</b><small>{{ __('Account settings') }}</small></span>
                        </a>
                    @endif
                    <a href="{{ $inboxUrl }}" class="rk-um__item">
                        <span class="rk-um__tile rk-um__tile--green"><x-heroicon-o-inbox class="h-6 w-6" /></span>
                        <span><b>{{ __('My inbox') }}</b><small>{{ __('Messages & emails') }}</small></span>
                        @if ($chatUnread)<em class="rk-um__badge">{{ $chatUnread }}</em>@endif
                    </a>
                    @if ($tasksUrl)
                        <a href="{{ $tasksUrl }}" class="rk-um__item">
                            <span class="rk-um__tile rk-um__tile--red"><x-heroicon-o-clipboard-check class="h-6 w-6" /></span>
                            <span><b>{{ __('My tickets') }}</b><small>{{ __('Your assigned and open work') }}</small></span>
                        </a>
                    @endif
                </nav>

                <form method="POST" action="{{ $logoutUrl }}">
                    @csrf
                    <button type="submit" class="rk-um__logout">{{ __('filament::layout.buttons.logout.label') }}</button>
                </form>
            </div>
            BLADE, ['chatUnread' => ($u = Filament::auth()->user()) ? \App\Support\ChatDirectory::unreadCount($u) : 0]));

        Filament::registerRenderHook('user-menu.start', fn (): string => $render(<<<'BLADE'
            <div class="topbar-profile-text">
                <span class="topbar-profile-name">{{ $name }}</span>
                @if ($role)<span class="topbar-profile-role">{{ $role }}</span>@endif
            </div>
            BLADE));
    }

    private function configureSocialiteProviders(): void
    {
        $configured = collect(config('filament-socialite.providers'))
            ->filter(fn ($providerConfig, $provider) => filled(config("services.{$provider}.client_id"))
                && filled(config("services.{$provider}.client_secret")))
            ->all();

        Config::set('filament-socialite.providers', $configured);
    }

    private function configureApp(): void
    {
        try {
            $settings = app(GeneralSettings::class);
            // setLocale (not Config::set) so the translator's active locale is
            // updated too, applying the configured site language app-wide.
            app()->setLocale($settings->site_language ?? config('app.fallback_locale'));
            Config::set('app.name', $settings->site_name ?? config('app.name'));
            Config::set('filament.brand', $settings->site_name ?? config('app.name'));
            Config::set(
                'app.logo',
                $settings->site_logo ? asset('storage/'.$settings->site_logo) : asset('favicon.ico')
            );
            Config::set('filament-breezy.enable_registration', $settings->enable_registration ?? false);
            Config::set('filament-socialite.registration', $settings->enable_registration ?? false);
            Config::set('filament-socialite.enabled', $settings->enable_social_login ?? false);
            Config::set('system.login_form.is_enabled', $settings->enable_login_form ?? false);
        } catch (\Throwable $e) {
            // Settings aren't available yet: no database configured, or a
            // pending settings migration (e.g. a newly added property). Fall
            // back to config defaults so the app can still boot — importantly,
            // so `php artisan migrate` can run to add that very property.
        }
    }
}

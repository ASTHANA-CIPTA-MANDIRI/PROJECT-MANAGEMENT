<?php

namespace App\Providers;

use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\BulkDeleteAuthorizer;
use App\Support\UserCountsMemo;
use DutchCodingCompany\FilamentSocialite\FilamentSocialite;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
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

        // Enforce a strong password policy on registration/profile/reset
        $this->configurePasswordPolicy();

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

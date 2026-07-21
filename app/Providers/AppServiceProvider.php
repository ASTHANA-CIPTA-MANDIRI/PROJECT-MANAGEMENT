<?php

namespace App\Providers;

use App\Models\User;
use App\Settings\GeneralSettings;
use DutchCodingCompany\FilamentSocialite\FilamentSocialite;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use App\Notifications\CustomVerifyEmail;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
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

        // Register custom Filament theme
        Filament::serving(function () {
            Filament::registerTheme(
                app(Vite::class)('resources/css/filament.scss'),
            );
        });

        // Register tippy styles
        Filament::registerStyles([
            'https://unpkg.com/tippy.js@6/dist/tippy.css',
        ]);

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
                                       href="' . config('app.logo') . '">'),
        ]);

        // Register navigation groups
        Filament::registerNavigationGroups([
            __('Management'),
            __('Referential'),
            __('Security'),
            __('Settings'),
        ]);

        // Force HTTPS over HTTP
        if (env('APP_FORCE_HTTPS') ?? false) {
            URL::forceScheme('https');
        }

        VerifyEmail::toMailUsing(function ($notifiable, $url) {
            return (new CustomVerifyEmail($url))->toMail($notifiable);
        });

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

    private function configureApp(): void
    {
        try {
            $settings = app(GeneralSettings::class);
            Config::set('app.locale', $settings->site_language ?? config('app.fallback_locale'));
            Config::set('app.name', $settings->site_name ?? env('APP_NAME'));
            Config::set('filament.brand', $settings->site_name ?? env('APP_NAME'));
            Config::set(
                'app.logo',
                $settings->site_logo ? asset('storage/' . $settings->site_logo) : asset('favicon.ico')
            );
            Config::set('filament-breezy.enable_registration', $settings->enable_registration ?? false);
            Config::set('filament-socialite.registration', $settings->enable_registration ?? false);
            Config::set('filament-socialite.enabled', $settings->enable_social_login ?? false);
            Config::set('system.login_form.is_enabled', $settings->enable_login_form ?? false);
        } catch (QueryException $e) {
            // Error: No database configured yet
        }
    }
}

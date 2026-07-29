<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The configured site language must become the active locale (config AND
 * translator), which is why AppServiceProvider uses app()->setLocale(). This
 * replaces the old LocaleMiddleware that only applied it on Filament routes.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_site_language_setting_becomes_the_active_locale(): void
    {
        app()->setLocale('en');

        GeneralSettings::fake([
            'site_name' => 'Test',
            'enable_registration' => false,
            'site_logo' => null,
            'enable_social_login' => null,
            'site_language' => 'ar',
            'default_role' => null,
            'enable_login_form' => null,
        ]);

        $method = new \ReflectionMethod(AppServiceProvider::class, 'configureApp');
        $method->setAccessible(true);
        $method->invoke(new AppServiceProvider($this->app));

        $this->assertSame('ar', app()->getLocale());
    }
}

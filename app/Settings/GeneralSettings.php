<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name;

    public bool $enable_registration;

    public ?string $site_logo;

    public ?string $enable_social_login;

    public ?string $site_language;

    public ?string $default_role;

    public ?string $enable_login_form;

    public static function group(): string
    {
        return 'general';
    }
}

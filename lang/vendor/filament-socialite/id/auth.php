<?php

// The package (dutchcodingcompany/filament-socialite) only ships an `en`
// translation set (see vendor/dutchcodingcompany/filament-socialite/resources/lang).
// App locale + fallback_locale are both `id` (config/app.php), so without this
// file Laravel's __() has nowhere to resolve `filament-socialite::auth.*` keys
// and prints the raw key literally instead of falling back to English.
return [

    'login-via' => 'Atau masuk melalui',

    'login-failed' => 'Login gagal, silakan coba lagi.',

    'user-not-allowed' => 'Email Anda bukan bagian dari domain yang diizinkan.',

    'registration-not-enabled' => 'Pendaftaran pengguna baru tidak diizinkan.',
];

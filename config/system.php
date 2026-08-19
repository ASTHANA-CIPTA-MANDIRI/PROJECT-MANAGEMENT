<?php

return [

    // Security policies
    'security' => [

        // Require Super Admins to set up two-factor authentication before they
        // can use the panel (they are redirected to their profile to enable it).
        'require_2fa_for_super_admin' => (bool) env('REQUIRE_2FA_FOR_SUPER_ADMIN', true),

    ],

    // Login form
    'login_form' => [

        // Enabled
        'is_enabled' => true,

    ],

    // Locales
    'locales' => [

        // Locales list
        'list' => [
            'en' => 'English',
            'fr' => 'French',
            'ar' => 'Arabic',
            'az' => 'Azerbaijani',
            'be' => 'Belarusian',
            'bg' => 'Bulgarian',
            'bn' => 'Bengali',
            'bs' => 'Bosnian',
            'ca' => 'Catalan',
            'cs' => 'Czech',
            'cy' => 'Welsh',
            'da' => 'Danish',
            'de' => 'German',
            'el' => 'Greek',
            'es' => 'Spanish',
            'et' => 'Estonian',
            'eu' => 'Basque',
            'fa' => 'Persian',
            'fi' => 'Finnish',
            'fil' => 'Filipino',
            'gl' => 'Galician',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hr' => 'Croatian',
            'hu' => 'Hungarian',
            'hy' => 'Armenian',
            'id' => 'Indonesian',
            'is' => 'Icelandic',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'ka' => 'Georgian',
            'kk' => 'Kazakh',
            'km' => 'Central Khmer',
            'kn' => 'Kannada',
            'ko' => 'Korean',
            'lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'mk' => 'Macedonian	',
            'mn' => 'Mongolian',
            'mr' => 'Marathi',
            'ms' => 'Malay',
            'nb' => 'Norwegian Bokmål',
            'ne' => 'Nepali',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ps' => 'Pashto',
            'pt' => 'Portuguese',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'si' => 'Sinhala',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'sq' => 'Albanian',
            'sv' => 'Swedish',
            'sw' => 'Swahili',
            'tg' => 'Tajik',
            'th' => 'Thai',
            'tk' => 'Turkmen',
            'tl' => 'Tagalog',
            'tr' => 'Turkish',
            'ug' => 'Uighur',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'vi' => 'Vietnamese',
        ],

    ],

    // Projects configuration
    'projects' => [

        // Users affectations
        'affectations' => [

            // Users affectations roles
            'roles' => [

                // Default role
                'default' => 'employee',

                // Role that can manage
                'can_manage' => 'administrator',

                // Roles list
                'list' => [
                    'employee' => 'Employee',
                    'customer' => 'Customer',
                    'administrator' => 'Administrator',
                ],

                // Roles colors
                'colors' => [
                    'primary' => 'employee',
                    'warning' => 'customer',
                    'danger' => 'administrator',
                ],

            ],

        ],

    ],

    // Tickets configuration
    'tickets' => [

        // Attachments configuration
        'attachments' => [

            // Accepted mime types (deliberately excludes html/svg to prevent
            // stored XSS when an attachment is opened same-origin)
            'accepted_mime_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'application/zip',
                'application/x-rar-compressed',
            ],

        ],

        // Ticket relations types
        'relations' => [

            // Default type
            'default' => 'related_to',

            // Types list
            'list' => [
                'related_to' => 'Related to',
                'blocked_by' => 'Blocked by',
                'duplicate_of' => 'Duplicate of',
            ],

            // Types colors
            'colors' => [
                'related_to' => 'primary',
                'blocked_by' => 'warning',
                'duplicate_of' => 'danger',
            ],

        ],

    ],

    // Images (project covers, site logo)
    'images' => [

        // Raster formats only - deliberately excludes image/svg+xml, which can
        // embed <script> and executes when its media.show URL (or, for the
        // site logo, its public disk URL) is opened directly.
        'accepted_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],

    ],

    // System constants
    'max_file_size' => 10240,

];

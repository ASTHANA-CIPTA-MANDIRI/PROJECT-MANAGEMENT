<?php

// Partial override, merged over vendor/spatie/laravel-medialibrary's defaults
// (MediaLibraryServiceProvider::mergeConfigFrom) - only the keys below differ.
return [

    /*
     * Ticket attachments and project covers must never be reachable without
     * going through TicketPolicy/ProjectPolicy, so the default disk is the
     * private "media" disk rather than the publicly symlinked "public" one.
     */
    'disk_name' => env('MEDIA_DISK', 'media'),

    /*
     * Generates URLs that resolve through the authenticated media.show route
     * (App\Http\Controllers\MediaController) instead of a raw disk/public URL.
     */
    'url_generator' => \App\Support\Media\AuthorizedUrlGenerator::class,

];

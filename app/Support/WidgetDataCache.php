<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Shared version stamp for dashboard widget caches (see
 * App\Filament\Widgets\Concerns\WithCachedData). Those widgets cache their
 * computed data per viewer for an hour; bumping this version invalidates
 * every cached entry, for every viewer and every widget using the trait, in
 * a single cache write, instead of enumerating users/keys or flushing the
 * whole cache store.
 */
class WidgetDataCache
{
    private const KEY = 'widget-data:version';

    public static function version(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public static function invalidate(): void
    {
        Cache::forever(self::KEY, self::version() + 1);
    }
}

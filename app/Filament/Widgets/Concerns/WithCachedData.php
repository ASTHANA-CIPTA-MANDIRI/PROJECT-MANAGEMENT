<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Caches a widget's computed data so its queries do not run on every dashboard
 * render/poll. Default TTL is one hour, as required for dashboard statistics.
 */
trait WithCachedData
{
    /** Cache lifetime for dashboard widget data, in seconds (1 hour). */
    protected int $cacheTtl = 3600;

    /**
     * Remember the result of $callback under a key scoped to both the widget
     * and the current viewer. Widget data is filtered per user, so a shared
     * key would serve one user's figures to the next one.
     *
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     */
    protected function remember(string $suffix, \Closure $callback)
    {
        return Cache::remember($this->userCacheKey($suffix), $this->cacheTtl, $callback);
    }

    /**
     * Build a stable, collision-free key for this widget (and optional scope).
     */
    protected function cacheKey(string $suffix = ''): string
    {
        return implode(':', array_filter([
            'widget',
            class_basename(static::class),
            $suffix,
        ]));
    }

    /**
     * Per-user variant of the key, for widgets whose data depends on the viewer.
     */
    protected function userCacheKey(string $suffix = ''): string
    {
        return $this->cacheKey(implode(':', array_filter(['user', auth()->id(), $suffix])));
    }
}

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // API endpoints: 100 requests/minute, counted per access token (so two
        // tokens of the same user get independent budgets). Falls back to the
        // user id, then the client IP for non-token contexts.
        RateLimiter::for('api', function (Request $request) {
            // Resolve via the sanctum guard explicitly: this limiter runs
            // before the route's auth:sanctum middleware, so the default guard
            // would not yet see the bearer token.
            $user = $request->user('sanctum') ?? $request->user();
            $token = $user?->currentAccessToken();

            if ($token && method_exists($token, 'getKey')) {
                $key = 'token:' . $token->getKey();
            } elseif ($user) {
                $key = 'user:' . $user->id;
            } else {
                $key = 'ip:' . $request->ip();
            }

            return Limit::perMinute(100)->by($key);
        });

        // Public (unauthenticated) endpoints: 60 requests/minute per IP.
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(60)->by('public:' . $request->ip());
        });
    }
}

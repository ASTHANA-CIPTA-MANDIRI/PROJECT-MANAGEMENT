<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use App\Support\TrialGate;
use Closure;
use Illuminate\Http\Request;

/**
 * The paywall: once an Organization's trial has ended and it has no active
 * subscription, TrialGate (Gate::before) already denies every ability on its
 * Project/Ticket/... models, which on its own leaves a member staring at 403s.
 * This sends them to the billing page instead, so they can see why and (Owner
 * or Admin) pay. It never grants or denies anything itself - authorization
 * stays with the Gate; this is navigation only.
 *
 * Registered in the Filament `auth` middleware group (config/filament.php)
 * after RequireTwoFactorForSuperAdmins, so it only ever sees an
 * authenticated, verified panel user. Deliberately narrow:
 *
 *  - full-page GET/HEAD navigation only. Livewire message requests do not run
 *    the Filament auth group anyway, and a Livewire/JSON/non-GET request
 *    redirected mid-flight would break the page, so those are skipped
 *    explicitly; the Gate still denies whatever they try to do.
 *  - the API is a different stack (routes/api.php) and never passes through
 *    here: a locked Organization keeps getting the Gate's plain denial.
 *  - a short list of routes stays reachable so there is never a redirect
 *    loop and the user can still pay, fix their profile or sign out.
 *  - only when the paywall is switched on AND checkout is enabled (see
 *    paywallActive()), so it can never trap people on a page that cannot
 *    sell them anything.
 *
 * Cheap checks first; the subscription query and the Super Admin lookup only
 * run for a user whose Organization really is past its trial.
 */
class RedirectLockedOrganizationToBilling
{
    /**
     * Routes a locked member can still open.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ROUTES = [
        'filament.pages.organization/billing',
        'filament.pages.organization',
        'filament.pages.my-profile',
        'filament.pages.create-organization',
        'filament.auth.logout',
    ];

    public static function paywallActive(): bool
    {
        return (bool) config('subscriptions.paywall_enabled')
            && (bool) config('subscriptions.checkout_enabled');
    }

    public function handle(Request $request, Closure $next)
    {
        if (! self::paywallActive()) {
            return $next($request);
        }

        if (
            ! ($request->isMethod('GET') || $request->isMethod('HEAD'))
            || $request->expectsJson()
            || $request->headers->has('X-Livewire')
        ) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        $user = $request->user();
        $organization = $user ? OrganizationContext::current($user) : null;

        if ($organization === null) {
            return $next($request);
        }

        // Grandfathered (null) or still inside the trial: nothing to look up.
        if ($organization->trial_ends_at === null || $organization->trial_ends_at->isFuture()) {
            return $next($request);
        }

        if (TrialGate::active($organization) || $user->isSuperAdmin()) {
            return $next($request);
        }

        return redirect()->route('filament.pages.organization/billing');
    }
}

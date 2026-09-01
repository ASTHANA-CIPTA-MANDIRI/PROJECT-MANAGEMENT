<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;

/**
 * When the policy is enabled, a Super Admin who has not set up two-factor
 * authentication is confined to the profile page (to enable it) and logout —
 * every other panel route redirects there. This hardens the highest-privilege
 * account without ever locking it out of the setup screen.
 */
class RequireTwoFactorForSuperAdmins
{
    /**
     * Routes a not-yet-enrolled Super Admin may still reach.
     *
     * @var array<int, string>
     */
    private array $allowed = [
        'filament.pages.my-profile',
        'filament.auth.logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (
            config('system.security.require_2fa_for_super_admin')
            && $user
            && $user->isSuperAdmin()
            && ! $user->has_confirmed_two_factor
            && ! in_array($request->route()?->getName(), $this->allowed, true)
        ) {
            // Notify only once per session so repeated navigation doesn't stack
            // the same warning; the redirect itself keeps enforcing the policy.
            if (! $request->session()->get('2fa_required_notified')) {
                Notification::make()
                    ->warning()
                    ->title(__('Two-factor authentication required'))
                    ->body(__('Please enable two-factor authentication to continue using the panel.'))
                    ->persistent()
                    ->send();

                $request->session()->put('2fa_required_notified', true);
            }

            return redirect()->route('filament.pages.my-profile');
        }

        return $next($request);
    }
}

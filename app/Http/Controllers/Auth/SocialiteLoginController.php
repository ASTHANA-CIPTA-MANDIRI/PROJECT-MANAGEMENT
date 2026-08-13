<?php

namespace App\Http\Controllers\Auth;

use DutchCodingCompany\FilamentSocialite\Http\Controllers\SocialiteLoginController as BaseController;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;

/**
 * The packaged controller logs a social user straight in with
 * `guard()->login()`. Two-factor authentication is only challenged by the
 * Breezy login form, so social sign-in was a side door around it: whoever
 * controlled the linked Google/GitHub account never had to present the second
 * factor.
 *
 * Here the login is held back for users who have confirmed 2FA; the session is
 * parked and the user must pass {@see \App\Http\Livewire\TwoFactorChallenge}
 * first. Users without 2FA are unaffected.
 */
class SocialiteLoginController extends BaseController
{
    /** Session key holding the not-yet-authenticated social login. */
    public const PENDING_SESSION_KEY = 'socialite.pending_two_factor';

    protected function loginUser(SocialiteUser $socialiteUser)
    {
        $user = $socialiteUser->user;

        if (! $user?->has_confirmed_two_factor) {
            return parent::loginUser($socialiteUser);
        }

        // Park the identity only — nothing is authenticated until the code is
        // verified. Regenerate first so the parked id cannot be planted in a
        // session fixated before the OAuth round trip.
        session()->regenerate();
        session()->put(self::PENDING_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'socialite_user_id' => $socialiteUser->getKey(),
        ]);

        return redirect()->route('two-factor-challenge');
    }
}

<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;

/**
 * The single place a personal access token is minted, shared by the JSON API
 * and by the panel's "API tokens" page so both hand out tokens on identical
 * terms.
 *
 * Two rules live here:
 *
 * - Every token gets `['*']`. Nothing in this application checks token
 *   abilities — authorization runs off the user's roles and the policies — so
 *   a narrower ability list would only look like a restriction without being
 *   one.
 * - Every token gets a stamped `expires_at`, clamped to the global window in
 *   `config('sanctum.expiration')`. Sanctum requires both the window and the
 *   column to be unexpired, so a caller can ask for a shorter life but never a
 *   longer one — and the stored date is then the honest answer to "when does
 *   this stop working", which the panel and the API both display.
 */
class ApiTokenIssuer
{
    /**
     * Issue a token for the user. The returned object carries the plain text
     * secret, which is readable exactly once — it is not stored anywhere.
     */
    public static function issue(User $user, string $name, ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        return $user->createToken($name, ['*'], static::expiry($expiresAt));
    }

    /**
     * The effective expiry: the requested date, or the end of the global
     * window, whichever comes first. Null only when neither is set — that is,
     * when expiration has been switched off in config.
     */
    public static function expiry(?DateTimeInterface $requested = null): ?CarbonInterface
    {
        $window = (int) config('sanctum.expiration');
        $cap = $window > 0 ? Carbon::now()->addMinutes($window) : null;
        $requested = $requested ? Carbon::parse($requested) : null;

        if ($cap === null) {
            return $requested;
        }

        return ($requested === null || $cap->lessThan($requested)) ? $cap : $requested;
    }
}

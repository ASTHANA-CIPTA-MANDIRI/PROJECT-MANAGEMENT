<?php

namespace App\Support\Doku;

/**
 * DOKU's HMAC-SHA256 signature (verified spec, section C - developers.doku.com,
 * "Best Practice" page) - the exact same algorithm both directions:
 *
 *  - Outbound (App\Services\Doku\DokuClient::createPayment()): we sign a
 *    request TO DOKU, Request-Target is DOKU's own endpoint path
 *    (config('doku.checkout_path')).
 *  - Inbound (App\Http\Controllers\DokuWebhookController): we verify a
 *    notification FROM DOKU, Request-Target is OUR notification endpoint's
 *    own path, not DOKU's - confirmed explicitly in the spec's "Best
 *    Practice" example, not a guess.
 *
 * Kept as one small, stateless class rather than duplicated in both
 * directions' callers, so a mistake in the component-string order or the
 * digest algorithm only has one place to go wrong (and one place tested).
 */
class DokuSignature
{
    /**
     * Digest = base64(SHA256(raw request/notification body)) - only
     * meaningful for a request that has a body. Must be computed from the
     * exact raw bytes that were/will be sent, never from a value that has
     * been JSON-decoded and re-encoded (key order/whitespace could differ
     * and the digest would silently stop matching) - see spec section C.5.
     */
    public static function digest(string $rawBody): string
    {
        return base64_encode(hash('sha256', $rawBody, true));
    }

    /**
     * The exact component string DOKU signs, in the exact required order,
     * newline-separated, no trailing newline (spec section C.1).
     */
    public static function componentString(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $requestTarget,
        string $digest
    ): string {
        return self::componentStringWithoutDigest($clientId, $requestId, $timestamp, $requestTarget)
            ."\nDigest:{$digest}";
    }

    /**
     * Component string for a request WITHOUT a body (e.g. the GET Check
     * Status call): the same four lines minus Digest, still no trailing
     * newline. Sign it with sign() like any other component string.
     */
    public static function componentStringWithoutDigest(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $requestTarget
    ): string {
        return "Client-Id:{$clientId}\n"
            ."Request-Id:{$requestId}\n"
            ."Request-Timestamp:{$timestamp}\n"
            ."Request-Target:{$requestTarget}";
    }

    /**
     * Signature = "HMACSHA256=" + base64(HMAC-SHA256(componentString, secretKey))
     * (spec section C.3, verbatim from DOKU's own PHP sample code).
     */
    public static function sign(string $componentString, string $secretKey): string
    {
        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $componentString, $secretKey, true));
    }

    /**
     * Recomputes the expected signature and compares it to the one DOKU
     * sent using hash_equals() (spec section C.4 - never ===/==, to avoid a
     * timing attack). Returns false, never throws, for a missing/empty
     * header - the caller (DokuWebhookController) is the one that decides
     * what HTTP status a rejection gets.
     */
    public static function verify(string $componentString, string $secretKey, ?string $headerSignature): bool
    {
        if ($headerSignature === null || $headerSignature === '') {
            return false;
        }

        return hash_equals(self::sign($componentString, $secretKey), $headerSignature);
    }
}

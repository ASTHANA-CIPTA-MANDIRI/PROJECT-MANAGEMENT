<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionActivationService;
use App\Support\Doku\DokuSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fase 9 - the DOKU Checkout payment notification endpoint (verified spec
 * section B). Registered in routes/api.php outside the auth:sanctum group
 * (DOKU is not one of our users and carries no Sanctum token) and outside
 * the 'web' middleware group (no CSRF token to send) - the 'api' group's
 * own throttle:api limiter still applies.
 *
 * Order of operations matches the spec exactly: raw body first (for the
 * digest), then the four required headers, then recompute and compare the
 * signature with hash_equals(), and ONLY on a match does anything touch the
 * database. Any failure at any step returns 4xx and does no further
 * processing - a request that never passes signature verification cannot
 * reach App\Services\SubscriptionActivationService at all.
 *
 * Required payload fields: order.invoice_number, order.amount and
 * transaction.status. transaction.original_request_id is OPTIONAL (the QRIS
 * notification sample in DOKU's docs does not carry it); when present it is
 * still cross-checked against the invoice by SubscriptionActivationService.
 */
class DokuWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriptionActivationService $activation): JsonResponse
    {
        // Raw bytes, not $request->json()->all() - the digest must be
        // computed from exactly what DOKU sent, before any JSON
        // decode/re-encode could change key order or whitespace (spec
        // section C.5).
        $rawBody = $request->getContent();

        $clientId = $request->header('Client-Id');
        $requestId = $request->header('Request-Id');
        $timestamp = $request->header('Request-Timestamp');
        $signature = $request->header('Signature');

        $this->logIncoming($request, $rawBody);

        if (! $clientId || ! $requestId || ! $timestamp || ! $signature) {
            return response()->json(['message' => 'Missing required signature headers.'], 400);
        }

        $configuredClientId = (string) config('doku.client_id');

        if ($configuredClientId === '' || ! hash_equals($configuredClientId, $clientId)) {
            Log::warning('DOKU webhook: unrecognized Client-Id', ['client_id' => $clientId]);

            return response()->json(['message' => 'Unrecognized Client-Id.'], 400);
        }

        // Request-Target for an INBOUND notification is OUR endpoint's own
        // path, not DOKU's - spec section C.1's explicit "Best Practice"
        // clarification, confirmed against the live docs, not assumed by
        // analogy with the outbound direction. Read from the request
        // itself (rather than a hardcoded string) so it can never drift
        // from wherever this route is actually mounted.
        $requestTarget = $request->getPathInfo();

        $digest = DokuSignature::digest($rawBody);
        $componentString = DokuSignature::componentString($clientId, $requestId, $timestamp, $requestTarget, $digest);

        if (! DokuSignature::verify($componentString, (string) config('doku.secret_key'), $signature)) {
            Log::warning('DOKU webhook: signature verification failed', ['request_id' => $requestId]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawBody, true);

        if (
            ! is_array($payload)
            || ! isset($payload['order']['invoice_number'], $payload['order']['amount'])
            || ! isset($payload['transaction']['status'])
        ) {
            Log::warning('DOKU webhook: malformed payload', ['request_id' => $requestId]);

            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        // Acknowledge fast (spec: "Acknowledge HTTP Notification
        // immediately") - everything from here on is a bounded, local DB
        // transaction, never a further outbound call.
        $result = $activation->process($payload);

        return response()->json(['message' => $result], 200);
    }

    /**
     * Diagnostic trace of every request that claims to be a DOKU call, written
     * BEFORE any verification so a notification that is later rejected (or
     * never reaches the database) still leaves evidence that DOKU did call.
     *
     * Only requests carrying at least one of the four DOKU headers are
     * logged: a bare POST with none of them is a tunnel keepalive ping or a
     * scanner, not DOKU, and would flood the log. Deliberately NOT logged: the
     * Signature value, the secret, and the body (only its length).
     */
    private function logIncoming(Request $request, string $rawBody): void
    {
        $present = [
            'client_id' => $request->headers->has('Client-Id'),
            'request_id' => $request->headers->has('Request-Id'),
            'request_timestamp' => $request->headers->has('Request-Timestamp'),
            'signature' => $request->headers->has('Signature'),
        ];

        if (! in_array(true, $present, true)) {
            return;
        }

        Log::info('DOKU webhook: request received', [
            'method' => $request->method(),
            'path' => $request->getPathInfo(),
            'headers_present' => $present,
            'client_id' => $request->header('Client-Id'),
            'request_id' => $request->header('Request-Id'),
            'body_length' => strlen($rawBody),
            'ip' => $request->ip(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'user_agent' => $request->userAgent(),
        ]);
    }
}

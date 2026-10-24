<?php

namespace App\Services\Doku;

use App\Support\Doku\DokuSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DOKU Checkout create-payment call (verified spec section A). Uses
 * Laravel's Http facade (Illuminate\Http\Client, itself Guzzle-backed)
 * rather than a raw Guzzle client the way App\Services\JiraImportService
 * does: JiraImportService's raw client exists specifically to pin the DNS
 * resolution of a user-supplied host (SSRF hardening) - DOKU's base URL is
 * always a fixed config value, never user input, so that concern does not
 * apply here, and Http::fake() gives Fase 8-9's tests a first-party way to
 * simulate DOKU's responses without a real network call.
 */
class DokuClient
{
    /**
     * @param  array{amount: int, invoice_number: string, currency: string}  $order
     * @return array{payment: array{url: string, token_id: string}, order: array}
     *
     * @throws DokuPaymentException
     */
    public function createPayment(string $requestId, array $order): array
    {
        $clientId = (string) config('doku.client_id');
        $secretKey = (string) config('doku.secret_key');
        $path = (string) config('doku.checkout_path');
        $baseUrl = (bool) config('doku.production')
            ? (string) config('doku.production_base_url')
            : (string) config('doku.sandbox_base_url');

        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $body = [
            'order' => [
                'amount' => $order['amount'],
                'invoice_number' => $order['invoice_number'],
                'currency' => $order['currency'],
            ],
            'payment' => [
                'payment_due_date' => (int) config('doku.payment_due_date_minutes'),
            ],
        ];

        if (($override = $this->notificationUrlOverride()) !== null) {
            $body['additional_info'] = ['override_notification_url' => $override];
        }

        // Signed and sent as the exact same raw bytes - see
        // DokuSignature::digest()'s own docblock on why this must never be
        // an array that gets encoded twice.
        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);

        $digest = DokuSignature::digest($rawBody);
        $componentString = DokuSignature::componentString($clientId, $requestId, $timestamp, $path, $digest);
        $signature = DokuSignature::sign($componentString, $secretKey);

        try {
            $response = Http::withHeaders([
                'Client-Id' => $clientId,
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => $signature,
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('doku.timeout'))
                ->connectTimeout((int) config('doku.connect_timeout'))
                ->withBody($rawBody, 'application/json')
                ->post($baseUrl.$path);
        } catch (\Throwable $e) {
            Log::error('DOKU create-payment request failed', [
                'invoice_number' => $order['invoice_number'],
                'exception' => $e->getMessage(),
            ]);

            throw new DokuPaymentException('Unable to reach DOKU.', 0, $e);
        }

        if (! $response->successful()) {
            Log::error('DOKU create-payment returned an error response', [
                'invoice_number' => $order['invoice_number'],
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new DokuPaymentException('DOKU rejected the payment request.');
        }

        // DOKU wraps the actual payload one level down, under a top-level
        // "response" key (alongside a "message" key like ["SUCCESS"]) -
        // verified against a real sandbox call, not flat at the root the
        // way an earlier reading of the spec assumed.
        $data = $response->json();
        $payload = $data['response'] ?? [];

        $url = $payload['payment']['url'] ?? null;
        $tokenId = $payload['payment']['token_id'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($tokenId) || $tokenId === '') {
            Log::error('DOKU create-payment response missing response.payment.url/token_id', [
                'invoice_number' => $order['invoice_number'],
                'body' => $response->body(),
            ]);

            throw new DokuPaymentException('DOKU response did not include a payment URL.');
        }

        return [
            'payment' => [
                'url' => $url,
                'token_id' => $tokenId,
            ],
            'order' => $payload['order'] ?? [],
        ];
    }

    /**
     * DOKU Check Status API (verified against the sandbox): GET
     * /orders/v1/status/{invoice_number}. A GET has no body, so the
     * signature omits Digest. The response is FLAT (order / transaction at
     * the root, no "response" wrapper like create-payment has) and shaped
     * like a notification payload, so it can be handed straight to
     * SubscriptionActivationService::process(). DOKU asks callers not to
     * check within 60 seconds of a payment.
     *
     * @return array<string, mixed>
     *
     * @throws DokuPaymentException
     */
    public function checkStatus(string $invoiceNumber): array
    {
        $clientId = (string) config('doku.client_id');
        $secretKey = (string) config('doku.secret_key');
        $path = rtrim((string) config('doku.status_path'), '/').'/'.rawurlencode($invoiceNumber);
        $baseUrl = (bool) config('doku.production')
            ? (string) config('doku.production_base_url')
            : (string) config('doku.sandbox_base_url');

        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $signature = DokuSignature::sign(
            DokuSignature::componentStringWithoutDigest($clientId, $requestId, $timestamp, $path),
            $secretKey
        );

        try {
            $response = Http::withHeaders([
                'Client-Id' => $clientId,
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => $signature,
            ])
                ->timeout((int) config('doku.timeout'))
                ->connectTimeout((int) config('doku.connect_timeout'))
                ->get($baseUrl.$path);
        } catch (\Throwable $e) {
            Log::error('DOKU check-status request failed', [
                'invoice_number' => $invoiceNumber,
                'exception' => $e->getMessage(),
            ]);

            throw new DokuPaymentException('Unable to reach DOKU.', 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('DOKU check-status returned an error response', [
                'invoice_number' => $invoiceNumber,
                'status' => $response->status(),
            ]);

            throw new DokuPaymentException('DOKU rejected the status request.');
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['order']['invoice_number'], $data['transaction']['status'])) {
            Log::error('DOKU check-status response missing order/transaction', ['invoice_number' => $invoiceNumber]);

            throw new DokuPaymentException('DOKU status response was malformed.');
        }

        return $data;
    }

    /**
     * The validated opt-in override, or null (field not sent). DOKU only
     * honours it when its path equals the configured Notification URL's path,
     * which for us is always our own notification route - so anything else is
     * rejected here rather than sent and silently ignored by DOKU.
     */
    private function notificationUrlOverride(): ?string
    {
        $url = trim((string) config('doku.notification_url_override'));

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $expectedPath = route('doku.notifications', [], false);

        if (
            $parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || ($parts['path'] ?? '') !== $expectedPath
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
        ) {
            Log::error('DOKU_NOTIFICATION_URL_OVERRIDE ignored: must be https with the exact path '.$expectedPath.' (DOKU requires the override path to equal the configured Notification URL path)');

            return null;
        }

        return $url;
    }
}

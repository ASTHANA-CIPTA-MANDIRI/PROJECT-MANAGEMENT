<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Diagnostic trace of inbound DOKU calls (see DokuWebhookController::logIncoming).
 * The point: a notification that is rejected before touching the database must
 * still leave evidence that DOKU called, while keepalive pings and scanners
 * (no DOKU header at all) must not flood the log.
 */
class DokuWebhookLoggingTest extends TestCase
{
    private const PATH = '/api/doku/notifications';

    protected function setUp(): void
    {
        parent::setUp();

        config(['doku.client_id' => 'MCH-LOG-TEST', 'doku.secret_key' => 'log-test-secret']);
    }

    public function test_a_request_with_doku_headers_is_logged_even_when_it_is_rejected(): void
    {
        Log::spy();

        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([
            'Client-Id' => 'MCH-LOG-TEST',
            'Request-Id' => 'req-123',
            'Signature' => 'HMACSHA256=super-secret-signature-value',
            'User-Agent' => 'DOKU-Notifier/1.0',
            'X-Forwarded-For' => '203.0.113.9',
        ]), '{"order":{"invoice_number":"INV-X"}}')->assertStatus(400);

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            return $message === 'DOKU webhook: request received'
                && $context['method'] === 'POST'
                && $context['path'] === '/api/doku/notifications'
                && $context['client_id'] === 'MCH-LOG-TEST'
                && $context['request_id'] === 'req-123'
                && $context['headers_present'] === [
                    'client_id' => true,
                    'request_id' => true,
                    'request_timestamp' => false,
                    'signature' => true,
                ]
                && $context['body_length'] === strlen('{"order":{"invoice_number":"INV-X"}}')
                && $context['user_agent'] === 'DOKU-Notifier/1.0'
                && array_key_exists('ip', $context)
                && array_key_exists('forwarded_for', $context)
                // Never the signature, the secret or the body itself.
                && ! str_contains(json_encode($context), 'super-secret-signature-value')
                && ! str_contains(json_encode($context), 'log-test-secret')
                && ! str_contains(json_encode($context), 'INV-X');
        });
    }

    public function test_a_bare_post_without_any_doku_header_is_not_logged(): void
    {
        Log::spy();

        $this->postJson(self::PATH, [])->assertStatus(400);

        Log::shouldNotHaveReceived('info');
    }
}

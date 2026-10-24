<?php

namespace Tests\Feature\Billing;

use App\Services\Doku\DokuClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * additional_info.override_notification_url (DOKU "Override Notification URL"
 * doc): opt-in, https only, path must equal our notification route's path.
 */
class DokuNotificationOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['doku.client_id' => 'MCH-OVR', 'doku.secret_key' => 'ovr-secret']);

        Http::fake(['*' => Http::response(['message' => ['SUCCESS'], 'response' => [
            'payment' => ['url' => 'https://staging.doku.com/x', 'token_id' => 't'],
        ]], 200)]);
    }

    private function sentBody(): array
    {
        $body = null;
        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->body();

            return true;
        });

        return [$body, json_decode($body, true)];
    }

    private function create(): void
    {
        app(DokuClient::class)->createPayment('req-1', ['amount' => 150000, 'invoice_number' => 'INV-1', 'currency' => 'IDR']);
    }

    public function test_the_field_is_not_sent_by_default(): void
    {
        config(['doku.notification_url_override' => null]);

        $this->create();

        [, $decoded] = $this->sentBody();
        $this->assertArrayNotHasKey('additional_info', $decoded);
    }

    public function test_the_field_is_sent_when_configured_and_the_digest_covers_it(): void
    {
        config(['doku.notification_url_override' => 'https://abc.lhr.life/api/doku/notifications']);

        $this->create();

        [$raw, $decoded] = $this->sentBody();
        $this->assertSame(
            ['override_notification_url' => 'https://abc.lhr.life/api/doku/notifications'],
            $decoded['additional_info']
        );
        // Slashes must not be escaped, and the Digest header must be of this exact raw body.
        $this->assertStringContainsString('https://abc.lhr.life/api/doku/notifications', $raw);
        Http::assertSent(function (Request $r) use ($raw) {
            $component = "Client-Id:MCH-OVR\nRequest-Id:req-1\nRequest-Timestamp:".$r->header('Request-Timestamp')[0]
                ."\nRequest-Target:/checkout/v1/payment\nDigest:".base64_encode(hash('sha256', $raw, true));

            return $r->header('Signature')[0] === 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, 'ovr-secret', true));
        });
    }

    /**
     * @dataProvider invalidOverrides
     */
    public function test_an_invalid_override_is_not_sent(string $value): void
    {
        config(['doku.notification_url_override' => $value]);

        $this->create();

        [, $decoded] = $this->sentBody();
        $this->assertArrayNotHasKey('additional_info', $decoded);
    }

    public static function invalidOverrides(): array
    {
        return [
            'http not https' => ['http://abc.lhr.life/api/doku/notifications'],
            'different path' => ['https://abc.lhr.life/hooks/doku'],
            'domain only' => ['https://abc.lhr.life'],
            'with query' => ['https://abc.lhr.life/api/doku/notifications?x=1'],
            'with userinfo' => ['https://u:p@abc.lhr.life/api/doku/notifications'],
            'garbage' => ['not a url'],
        ];
    }
}

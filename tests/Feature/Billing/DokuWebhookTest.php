<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Doku\DokuSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 9 - POST /api/doku/notifications
 * (App\Http\Controllers\DokuWebhookController +
 * App\Services\SubscriptionActivationService). Every request in this file
 * builds its own genuinely valid signature via DokuSignature - the exact
 * same helper the controller uses to verify - so a passing "valid
 * signature" test here is proof the verification math is internally
 * consistent, and the "invalid signature" tests prove a real DOKU
 * notification's protections actually reject a forgery/tamper attempt,
 * not just that our own test fixture happens to look right.
 */
class DokuWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'MCH-0001-TEST';

    private const SECRET_KEY = 'test-secret-key';

    private const PATH = '/api/doku/notifications';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doku.client_id' => self::CLIENT_ID,
            'doku.secret_key' => self::SECRET_KEY,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>} [rawBody, headers]
     */
    private function signedRequest(array $payload, ?string $timestamp = null): array
    {
        $rawBody = json_encode($payload);
        $requestId = (string) Str::uuid();
        $timestamp ??= now()->utc()->format('Y-m-d\TH:i:s\Z');

        $digest = DokuSignature::digest($rawBody);
        $componentString = DokuSignature::componentString(self::CLIENT_ID, $requestId, $timestamp, self::PATH, $digest);
        $signature = DokuSignature::sign($componentString, self::SECRET_KEY);

        return [$rawBody, [
            'Client-Id' => self::CLIENT_ID,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
            'Content-Type' => 'application/json',
        ]];
    }

    private function successPayload(Invoice $invoice): array
    {
        return [
            'service' => ['id' => 'VIRTUAL_ACCOUNT'],
            'order' => [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
            ],
            'transaction' => [
                'status' => 'SUCCESS',
                'date' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'original_request_id' => $invoice->doku_request_id,
            ],
        ];
    }

    private function postSigned(array $payload, ?string $timestamp = null)
    {
        [$rawBody, $headers] = $this->signedRequest($payload, $timestamp);

        return $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars($headers), $rawBody);
    }

    // --------------------------------------------------------- signature

    public function test_a_validly_signed_success_notification_is_accepted(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        $response = $this->postSigned($this->successPayload($invoice));

        $response->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_missing_signature_headers_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        $response = $this->postJson(self::PATH, $this->successPayload($invoice));

        $response->assertStatus(400);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        [$rawBody, $headers] = $this->signedRequest($this->successPayload($invoice));
        $headers['Signature'] = 'HMACSHA256=not-the-real-signature';

        $response = $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars($headers), $rawBody);

        $response->assertStatus(400);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    /**
     * The signature covers the body via the digest - editing the body
     * after signing (without re-signing) must invalidate it, proving the
     * digest is actually load-bearing and not merely decorative.
     */
    public function test_a_tampered_body_after_signing_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        [, $headers] = $this->signedRequest($this->successPayload($invoice));
        $tamperedBody = json_encode(array_replace_recursive($this->successPayload($invoice), [
            'order' => ['amount' => 1],
        ]));

        $response = $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars($headers), $tamperedBody);

        $response->assertStatus(400);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_an_unrecognized_client_id_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        $payload = $this->successPayload($invoice);
        $rawBody = json_encode($payload);
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $digest = DokuSignature::digest($rawBody);
        // Signed correctly for a DIFFERENT client id than the one configured.
        $componentString = DokuSignature::componentString('SOMEONE-ELSE', $requestId, $timestamp, self::PATH, $digest);
        $signature = DokuSignature::sign($componentString, self::SECRET_KEY);

        $response = $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([
            'Client-Id' => 'SOMEONE-ELSE',
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
            'Content-Type' => 'application/json',
        ]), $rawBody);

        $response->assertStatus(400);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $response = $this->postSigned(['not' => 'the expected shape']);

        $response->assertStatus(400);
    }

    public function test_invalid_json_body_is_rejected(): void
    {
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $rawBody = '{not valid json';
        $digest = DokuSignature::digest($rawBody);
        $componentString = DokuSignature::componentString(self::CLIENT_ID, $requestId, $timestamp, self::PATH, $digest);
        $signature = DokuSignature::sign($componentString, self::SECRET_KEY);

        $response = $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([
            'Client-Id' => self::CLIENT_ID,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
        ]), $rawBody);

        $response->assertStatus(400);
    }

    // --------------------------------------------------------- matching

    public function test_an_unknown_invoice_number_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $payload = $this->successPayload($invoice);
        $payload['order']['invoice_number'] = 'INV-DOES-NOT-EXIST';

        $response = $this->postSigned($payload);

        $response->assertOk(); // acknowledged, but a no-op
        $this->assertSame('unknown_invoice', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_an_original_request_id_that_does_not_match_the_invoice_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $payload = $this->successPayload($invoice);
        $payload['transaction']['original_request_id'] = (string) Str::uuid(); // not what checkout actually sent

        $response = $this->postSigned($payload);

        $response->assertOk();
        $this->assertSame('reference_mismatch', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    // QRIS notifications carry no transaction.original_request_id.

    public function test_a_notification_without_original_request_id_is_accepted_and_paid(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create();
        $payload = $this->successPayload($invoice);
        unset($payload['transaction']['original_request_id']);

        $response = $this->postSigned($payload);

        $response->assertOk();
        $this->assertSame('paid', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($invoice->organization->fresh()->isSubscribed());
    }

    public function test_a_correct_original_request_id_is_still_accepted(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create();

        $response = $this->postSigned($this->successPayload($invoice));

        $this->assertSame('paid', $response->json('message'));
    }

    public function test_without_original_request_id_an_amount_mismatch_is_still_rejected(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create(['amount' => 150000]);
        $payload = $this->successPayload($invoice);
        unset($payload['transaction']['original_request_id']);
        $payload['order']['amount'] = 1;

        $response = $this->postSigned($payload);

        $this->assertSame('amount_mismatch', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_a_duplicate_notification_without_original_request_id_is_idempotent(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create();
        $payload = $this->successPayload($invoice);
        unset($payload['transaction']['original_request_id']);

        $this->postSigned($payload);
        $second = $this->postSigned($payload);

        $this->assertSame('already_processed', $second->json('message'));
        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
    }

    public function test_an_expired_status_marks_the_invoice_expired_not_failed(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create();
        $payload = $this->successPayload($invoice);
        $payload['transaction']['status'] = 'EXPIRED';

        $response = $this->postSigned($payload);

        $this->assertSame('expired', $response->json('message'));
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertFalse($invoice->organization->fresh()->isSubscribed());
    }

    /**
     * Security: amount/organization from the payload is never trusted at
     * face value - only used to CONFIRM the invoice we already resolved by
     * invoice_number, and rejected outright on any mismatch.
     */
    public function test_an_amount_mismatch_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create(['amount' => 150000]);
        $payload = $this->successPayload($invoice);
        $payload['order']['amount'] = 1;

        $response = $this->postSigned($payload);

        $response->assertOk();
        $this->assertSame('amount_mismatch', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    // --------------------------------------------------------- status handling

    public function test_success_status_activates_a_new_subscription(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create(['plan_code' => 'monthly']);

        $this->postSigned($this->successPayload($invoice));

        $this->assertTrue($organization->fresh()->isSubscribed());
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_a_failed_status_does_not_activate_a_subscription(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $payload = $this->successPayload($invoice);
        $payload['transaction']['status'] = 'FAILED';

        $response = $this->postSigned($payload);

        $response->assertOk();
        $this->assertSame('ignored', $response->json('message'));
        // DOKU Best Practice: for Checkout, FAILED must be ignored (the
        // customer may switch payment method and still pay).
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertFalse($organization->fresh()->isSubscribed());
    }

    /**
     * Regression: FAILED used to be final, so the later real SUCCESS on the
     * same checkout was dropped as already_processed (paid, no subscription).
     */
    public function test_a_success_after_a_failed_attempt_still_activates(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $failed = $this->successPayload($invoice);
        $failed['transaction']['status'] = 'FAILED';

        $this->postSigned($failed)->assertOk();
        $response = $this->postSigned($this->successPayload($invoice));

        $this->assertSame('paid', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($organization->fresh()->isSubscribed());
    }

    public function test_string_and_decimal_amounts_are_matched_numerically(): void
    {
        foreach (['150000', 150000.0, '150000.00'] as $amount) {
            $organization = Organization::factory()->create();
            $invoice = Invoice::factory()->for($organization)->create(['amount' => 150000]);
            $payload = $this->successPayload($invoice);
            $payload['order']['amount'] = $amount;

            $this->assertSame('paid', $this->postSigned($payload)->json('message'), var_export($amount, true));
        }
    }

    /**
     * Any status other than the one verified, documented "SUCCESS" value
     * is treated as not-successful and never activates a subscription -
     * default-deny for anything undocumented, not an attempt to enumerate
     * DOKU's full status taxonomy (see the Fase 8-9 final report).
     */
    public function test_an_undocumented_status_value_does_not_activate_a_subscription(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $payload = $this->successPayload($invoice);
        $payload['transaction']['status'] = 'SOMETHING_WE_HAVE_NEVER_SEEN';

        $response = $this->postSigned($payload);

        $response->assertOk();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertFalse($organization->fresh()->isSubscribed());
    }

    // --------------------------------------------------------- idempotency

    /**
     * The spec explicitly warns duplicate notifications can arrive more
     * than once. Sending the exact same valid SUCCESS notification twice
     * must only ever activate the subscription once - not extend it twice,
     * not create two Subscription rows.
     */
    public function test_a_duplicate_success_notification_does_not_double_activate(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create(['plan_code' => 'monthly']);
        $payload = $this->successPayload($invoice);

        $first = $this->postSigned($payload);
        $second = $this->postSigned($payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame('paid', $first->json('message'));
        $this->assertSame('already_processed', $second->json('message'));
        $this->assertSame(1, Subscription::where('organization_id', $organization->id)->count());
    }

    /**
     * Same idempotency guard, but the duplicate delivery carries a
     * DIFFERENT (impossible/adversarial) status the second time around -
     * a final invoice must never transition again regardless of what a
     * later notification claims.
     */
    public function test_a_final_invoice_never_transitions_again_even_to_a_different_status(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();

        $this->postSigned($this->successPayload($invoice));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $failedReplay = $this->successPayload($invoice);
        $failedReplay['transaction']['status'] = 'FAILED';
        $response = $this->postSigned($failedReplay);

        $response->assertOk();
        $this->assertSame('already_processed', $response->json('message'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status); // still paid, not downgraded
    }

    // --------------------------------------------------------- renewal

    /**
     * Conservative renewal assumption (flagged in the Fase 8-9 final
     * report): buying again while already active extends from the
     * existing ends_at, never from now() - so a renewal purchase can never
     * shorten time the organization already paid for.
     */
    public function test_paying_while_already_subscribed_extends_from_the_existing_end_date_not_from_now(): void
    {
        $organization = Organization::factory()->create();
        $existingEndsAt = now()->addDays(10);
        Subscription::factory()->for($organization)->create([
            'ends_at' => $existingEndsAt,
            'cancelled_at' => null,
        ]);
        $invoice = Invoice::factory()->for($organization)->create(['plan_code' => 'monthly']); // 30 days

        $this->postSigned($this->successPayload($invoice));

        $subscription = Subscription::where('organization_id', $organization->id)->first();
        $this->assertEqualsWithDelta(
            $existingEndsAt->copy()->addDays(30)->timestamp,
            $subscription->ends_at->timestamp,
            2
        );
        $this->assertSame(1, Subscription::where('organization_id', $organization->id)->count());
    }

    // --------------------------------------------------------- tenant isolation

    public function test_a_paid_invoice_only_ever_activates_its_own_organizations_subscription(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organizationA)->create();

        $this->postSigned($this->successPayload($invoice));

        $this->assertTrue($organizationA->fresh()->isSubscribed());
        $this->assertFalse($organizationB->fresh()->isSubscribed());
    }

    // --------------------------------------------------------- role safety

    /**
     * The webhook must never be able to grant a role - subscription state
     * and Spatie role/permission assignment are fully independent axes
     * (CLAUDE.md). Asserted here by proving nothing about the paying
     * organization's members' roles changes at all.
     */
    public function test_webhook_processing_never_assigns_or_changes_any_role(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->for($organization)->create();
        $user = \App\Models\User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'member']);

        $this->postSigned($this->successPayload($invoice));

        $this->assertSame('member', $organization->fresh()->roleOf($user));
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Doku\DokuSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * subscriptions:sync-pending-invoices - the Check Status polling fallback.
 * Fake response bodies mirror a REAL sandbox response (flat: order /
 * transaction at the root, no "response" wrapper), captured 2026-10-23.
 */
class SyncPendingInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.doku.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doku.client_id' => 'MCH-0001-TEST',
            'doku.secret_key' => 'test-secret-key',
            'doku.production' => false,
        ]);
    }

    private function pendingInvoice(array $attrs = []): Invoice
    {
        return Invoice::factory()->for(Organization::factory()->create())->create(array_merge([
            'created_at' => now()->subMinutes(10),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function statusBody(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null): array
    {
        return [
            'order' => [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $amount ?? $invoice->amount,
                'status' => $status === 'SUCCESS' ? 'ORDER_GENERATED' : 'ORDER_'.$status,
            ],
            'transaction' => array_merge([
                'status' => $status,
                'original_request_id' => $invoice->doku_request_id,
            ], $transaction),
            'service' => ['id' => 'VIRTUAL_ACCOUNT'],
            'acquirer' => ['id' => 'BCA', 'name' => 'BCA'],
            'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA'],
        ];
    }

    private function fakeStatus(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response(
                $this->statusBody($invoice, $status, $transaction, $amount)
            ),
        ]);
    }

    public function test_success_marks_paid_and_creates_subscription(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->artisan('subscriptions:sync-pending-invoices')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($invoice->organization->fresh()->isSubscribed());
        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
    }

    public function test_success_without_original_request_id_is_paid(): void
    {
        $invoice = $this->pendingInvoice();
        Http::fake([
            '*' => Http::response([
                'order' => ['invoice_number' => $invoice->invoice_number, 'amount' => $invoice->amount],
                'transaction' => ['status' => 'SUCCESS'],
            ]),
        ]);

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_the_status_request_is_signed_without_digest(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'PENDING');

        $this->artisan('subscriptions:sync-pending-invoices');

        Http::assertSent(function (Request $request) use ($invoice) {
            $path = '/orders/v1/status/'.$invoice->invoice_number;
            $expected = DokuSignature::sign(
                DokuSignature::componentStringWithoutDigest(
                    'MCH-0001-TEST',
                    $request->header('Request-Id')[0],
                    $request->header('Request-Timestamp')[0],
                    $path
                ),
                'test-secret-key'
            );

            return $request->method() === 'GET'
                && $request->url() === self::BASE.$path
                && $request->header('Client-Id')[0] === 'MCH-0001-TEST'
                && $request->header('Signature')[0] === $expected;
        });
    }

    public function test_pending_stays_pending(): void
    {
        foreach (['PENDING', 'REDIRECT', 'TIMEOUT', 'REFUNDED'] as $status) {
            $invoice = $this->pendingInvoice();
            $this->fakeStatus($invoice, $status);

            $this->artisan('subscriptions:sync-pending-invoices');

            $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status, $status);
            $invoice->delete();
        }
    }

    public function test_expired_at_doku_marks_invoice_expired_not_failed(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'EXPIRED');

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertFalse($invoice->organization->fresh()->isSubscribed());
    }

    public function test_failed_at_doku_leaves_invoice_pending_because_checkout_allows_retry(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'FAILED');

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $invoice = $this->pendingInvoice(['amount' => 150000]);
        $this->fakeStatus($invoice, 'SUCCESS', [], 1);

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertFalse($invoice->organization->fresh()->isSubscribed());
    }

    public function test_wrong_original_request_id_is_rejected(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS', ['original_request_id' => 'someone-elses']);

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_final_invoices_are_not_polled_or_reprocessed(): void
    {
        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())
            ->create(['created_at' => now()->subHour()]);
        Http::fake();

        $this->artisan('subscriptions:sync-pending-invoices');

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);
    }

    public function test_http_error_does_not_crash_and_next_invoice_is_still_processed(): void
    {
        $broken = $this->pendingInvoice();
        $good = $this->pendingInvoice();
        Http::fake([
            self::BASE.'/orders/v1/status/'.$broken->invoice_number => Http::response('boom', 500),
            self::BASE.'/orders/v1/status/'.$good->invoice_number => Http::response($this->statusBody($good, 'SUCCESS')),
        ]);

        $this->artisan('subscriptions:sync-pending-invoices')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PENDING, $broken->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $good->fresh()->status);
    }

    public function test_network_exception_does_not_crash(): void
    {
        $invoice = $this->pendingInvoice();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->artisan('subscriptions:sync-pending-invoices')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_invoice_younger_than_60_seconds_is_skipped(): void
    {
        $invoice = $this->pendingInvoice(['created_at' => now()->subSeconds(20)]);
        Http::fake();

        $this->artisan('subscriptions:sync-pending-invoices');

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_invoice_just_past_expiry_but_inside_the_grace_window_is_still_polled(): void
    {
        $invoice = $this->pendingInvoice(['expires_at' => now()->subMinutes(10)]);
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_expire_command_waits_for_the_grace_window_before_closing_an_invoice(): void
    {
        $inside = $this->pendingInvoice(['expires_at' => now()->subMinutes(10)]);
        $outside = $this->pendingInvoice(['expires_at' => now()->subMinutes(45)]);

        $this->artisan('subscriptions:expire-invoices')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PENDING, $inside->fresh()->status);
        $this->assertSame(Invoice::STATUS_EXPIRED, $outside->fresh()->status);
    }

    public function test_invoice_far_past_its_expiry_is_not_polled(): void
    {
        $this->pendingInvoice(['expires_at' => now()->subHours(3)]);
        Http::fake();

        $this->artisan('subscriptions:sync-pending-invoices');

        Http::assertNothingSent();
    }

    public function test_running_twice_is_idempotent(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->artisan('subscriptions:sync-pending-invoices');
        $this->artisan('subscriptions:sync-pending-invoices');

        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_invoice_option_syncs_only_that_invoice_even_if_young(): void
    {
        $target = $this->pendingInvoice(['created_at' => now()->subSeconds(5)]);
        $other = $this->pendingInvoice();
        $this->fakeStatus($target, 'SUCCESS');

        $this->artisan('subscriptions:sync-pending-invoices', ['--invoice' => $target->invoice_number])
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PAID, $target->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $other->fresh()->status);
    }

    public function test_invoice_option_with_unknown_or_final_invoice_fails(): void
    {
        Http::fake();

        $this->artisan('subscriptions:sync-pending-invoices', ['--invoice' => 'INV-NOPE'])->assertFailed();

        Http::assertNothingSent();
    }
}

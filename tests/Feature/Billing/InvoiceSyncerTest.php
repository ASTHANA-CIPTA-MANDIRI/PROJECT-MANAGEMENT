<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Doku\DokuPaymentException;
use App\Services\InvoiceSyncer;
use App\Services\StatusSnapshot;
use App\Services\SubscriptionActivationService;
use App\Services\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * App\Services\InvoiceSyncer - the single-invoice Check Status door used by
 * Super Admin actions. Fake bodies mirror a REAL sandbox response (flat: no
 * "response" wrapper), the same fixture SyncPendingInvoicesTest uses.
 */
class InvoiceSyncerTest extends TestCase
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

    private function syncer(): InvoiceSyncer
    {
        return app(InvoiceSyncer::class);
    }

    private function pendingInvoice(array $attrs = []): Invoice
    {
        return Invoice::factory()->for(Organization::factory()->create())->create(array_merge([
            'created_at' => now()->subMinutes(10),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function statusBody(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null, ?string $invoiceNumber = null): array
    {
        return [
            'order' => [
                'invoice_number' => $invoiceNumber ?? $invoice->invoice_number,
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

    private function fakeStatus(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null, ?string $invoiceNumber = null): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response(
                $this->statusBody($invoice, $status, $transaction, $amount, $invoiceNumber)
            ),
        ]);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private const REASON = 'Pelanggan sudah membayar tetapi webhook tidak masuk';

    // ---- check() ----

    public function test_check_returns_redacted_snapshot_and_writes_nothing(): void
    {
        $invoice = $this->pendingInvoice();
        $before = $invoice->fresh()->getAttributes();

        Http::fake([
            '*' => Http::response(array_merge($this->statusBody($invoice, 'SUCCESS', ['date' => '2026-10-26T01:02:03Z']), [
                'payment' => ['url' => 'https://evil.example/URL-SENTINEL', 'token_id' => 'TOKEN-SENTINEL'],
                'headers' => ['signature' => 'SIGNATURE-SENTINEL'],
            ])),
        ]);

        $snapshot = $this->syncer()->check($invoice);

        $this->assertInstanceOf(StatusSnapshot::class, $snapshot);
        $this->assertSame($invoice->invoice_number, $snapshot->invoiceNumber);
        $this->assertSame('SUCCESS', $snapshot->dokuStatus);
        $this->assertSame(150000, $snapshot->dokuAmount);
        $this->assertSame('VIRTUAL_ACCOUNT_BCA', $snapshot->channel);
        $this->assertSame('VIRTUAL_ACCOUNT', $snapshot->service);
        $this->assertSame('BCA', $snapshot->acquirerName);
        $this->assertTrue($snapshot->matchesInvoice);
        $this->assertSame('2026-10-26 01:02:03', $snapshot->transactionAt->utc()->format('Y-m-d H:i:s'));

        $dump = json_encode(get_object_vars($snapshot), JSON_PARTIAL_OUTPUT_ON_ERROR).serialize($snapshot);
        foreach (['URL-SENTINEL', 'TOKEN-SENTINEL', 'SIGNATURE-SENTINEL', $invoice->doku_request_id, $invoice->doku_token_id, $invoice->doku_payment_url, 'test-secret-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, BillingAuditLog::count(), 'No actor, no audit row.');
    }

    public function test_check_leaves_transaction_time_null_when_doku_does_not_send_it(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'PENDING');

        $this->assertNull($this->syncer()->check($invoice)->transactionAt);
    }

    public function test_check_flags_a_response_about_another_invoice(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS', invoiceNumber: 'INV-SOMEONE-ELSE');

        $this->assertFalse($this->syncer()->check($invoice)->matchesInvoice);
    }

    public function test_check_throws_doku_exception_when_doku_fails(): void
    {
        $invoice = $this->pendingInvoice();
        Http::fake(['*' => Http::response([], 500)]);

        $this->expectException(DokuPaymentException::class);

        $this->syncer()->check($invoice);
    }

    // ---- sync() ----

    public function test_sync_success_pays_the_invoice_and_creates_one_subscription(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::PAID, $outcome->result);
        $this->assertTrue($outcome->changedInvoice());
        $this->assertSame('pending', $outcome->oldStatus);
        $this->assertSame('paid', $outcome->newStatus);
        $this->assertSame('SUCCESS', $outcome->dokuStatus);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
    }

    public function test_sync_expired_expires_the_invoice(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'EXPIRED');

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::EXPIRED, $outcome->result);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    /**
     * @dataProvider unforwardedStatuses
     */
    public function test_sync_does_not_forward_other_statuses(string $status): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, $status);

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::NOT_FORWARDED, $outcome->result);
        $this->assertSame($status, $outcome->dokuStatus);
        $this->assertFalse($outcome->changedInvoice());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    /** @return array<string, array<int, string>> */
    public static function unforwardedStatuses(): array
    {
        return array_combine(
            ['PENDING', 'FAILED', 'REDIRECT', 'TIMEOUT', 'REFUNDED', 'WHATEVER'],
            array_map(fn ($s) => [$s], ['PENDING', 'FAILED', 'REDIRECT', 'TIMEOUT', 'REFUNDED', 'WHATEVER'])
        );
    }

    public function test_sync_rejects_a_response_about_another_invoice(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS', invoiceNumber: 'INV-SOMEONE-ELSE');

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::INVOICE_MISMATCH, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_reports_an_amount_mismatch_and_leaves_the_invoice_pending(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS', amount: 1);

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::AMOUNT_MISMATCH, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_reports_a_reference_mismatch_and_leaves_the_invoice_pending(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS', ['original_request_id' => 'not-our-request-id']);

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::REFERENCE_MISMATCH, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_reports_doku_error_without_leaking_an_exception(): void
    {
        $invoice = $this->pendingInvoice();
        Http::fake(['*' => Http::response([], 500)]);

        $this->assertSame(SyncOutcome::DOKU_UNREACHABLE, $this->syncer()->sync($invoice)->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_sync_reports_a_timeout_without_leaking_an_exception(): void
    {
        $invoice = $this->pendingInvoice();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->assertSame(SyncOutcome::DOKU_UNREACHABLE, $this->syncer()->sync($invoice)->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_sync_reports_apply_failed_when_process_throws_and_the_invoice_is_unchanged(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->mock(SubscriptionActivationService::class, function ($mock) {
            $mock->shouldReceive('process')->once()->andThrow(new \RuntimeException('SQLSTATE secret detail'));
        });

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::APPLY_FAILED, $outcome->result);
        $this->assertFalse($outcome->changedInvoice());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_of_a_non_pending_invoice_never_calls_doku(): void
    {
        Http::fake();
        $invoice = Invoice::factory()->paid()->for(Organization::factory()->create())->create(['created_at' => now()->subHour()]);

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $outcome->result);
        $this->assertSame('paid', $outcome->oldStatus);
        Http::assertNothingSent();
    }

    public function test_sync_of_a_too_young_invoice_never_calls_doku(): void
    {
        Http::fake();
        $invoice = $this->pendingInvoice(['created_at' => now()->subSeconds(InvoiceSyncer::MIN_AGE_SECONDS - 5)]);

        $outcome = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::TOO_EARLY, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_sync_of_a_vanished_invoice_is_unknown_and_never_calls_doku(): void
    {
        Http::fake();
        $invoice = $this->pendingInvoice();
        $ghost = $invoice->replicate();
        $ghost->id = $invoice->id;
        $invoice->delete();

        $this->assertSame(SyncOutcome::UNKNOWN_INVOICE, $this->syncer()->sync($ghost)->result);
        Http::assertNothingSent();
    }

    public function test_sync_decides_on_the_current_row_not_on_the_callers_stale_copy(): void
    {
        Http::fake();
        $stale = $this->pendingInvoice();
        Invoice::whereKey($stale->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $this->syncer()->sync($stale)->result);
        Http::assertNothingSent();
    }

    public function test_two_consecutive_syncs_activate_only_once(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $first = $this->syncer()->sync($invoice);
        $second = $this->syncer()->sync($invoice);

        $this->assertSame(SyncOutcome::PAID, $first->result);
        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $second->result);
        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
    }

    /**
     * @dataProvider badReasons
     */
    public function test_sync_with_an_actor_requires_a_non_blank_reason_of_at_most_500_characters(?string $reason): void
    {
        Http::fake();
        $invoice = $this->pendingInvoice();

        try {
            $this->syncer()->sync($invoice, $this->actor(), $reason);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
            Http::assertNothingSent();
            $this->assertSame(0, BillingAuditLog::count());
        }
    }

    /** @return array<string, array<int, ?string>> */
    public static function badReasons(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['            '],
            'too long' => [str_repeat('a', 501)],
        ];
    }

    public function test_a_one_character_reason_and_a_500_character_reason_are_accepted(): void
    {
        foreach (['x', '  x  ', str_repeat('a', 500)] as $reason) {
            $invoice = $this->pendingInvoice();
            $this->fakeStatus($invoice, 'SUCCESS');

            $this->assertSame(SyncOutcome::PAID, $this->syncer()->sync($invoice, $this->actor(), $reason)->result);
        }
    }

    public function test_sync_without_an_actor_needs_no_reason(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->assertSame(SyncOutcome::PAID, $this->syncer()->sync($invoice)->result);
    }

    public function test_syncer_has_no_activation_path_of_its_own(): void
    {
        $source = file_get_contents(app_path('Services/InvoiceSyncer.php'));

        $this->assertStringNotContainsString('Invoice::update', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('Subscription::create', $source);
        $this->assertStringNotContainsString('Invoice::create', $source);
        $this->assertStringContainsString('->process(', $source);
    }
}

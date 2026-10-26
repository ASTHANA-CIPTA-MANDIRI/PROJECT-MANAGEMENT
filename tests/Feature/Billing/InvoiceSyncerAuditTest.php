<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceSyncer;
use App\Services\SubscriptionActivationService;
use App\Services\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What InvoiceSyncer records in billing_audit_logs for an attributed admin
 * action, and what it must never record.
 */
class InvoiceSyncerAuditTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.doku.com';

    private const REASON = 'Pelanggan sudah membayar tetapi webhook tidak masuk';

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

    private function pendingInvoice(): Invoice
    {
        return Invoice::factory()->for(Organization::factory()->create())->create([
            'created_at' => now()->subMinutes(10),
        ]);
    }

    /** Includes fields an audit row must never copy. */
    private function fakeStatus(Invoice $invoice, string $status, ?int $amount = null): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response([
                'order' => ['invoice_number' => $invoice->invoice_number, 'amount' => $amount ?? $invoice->amount],
                'transaction' => ['status' => $status, 'original_request_id' => $invoice->doku_request_id],
                'service' => ['id' => 'VIRTUAL_ACCOUNT'],
                'acquirer' => ['id' => 'BCA', 'name' => 'BCA'],
                'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA'],
                'payment' => ['url' => 'https://evil.example/URL-SENTINEL', 'token_id' => 'TOKEN-SENTINEL'],
                'signature' => 'SIGNATURE-SENTINEL',
            ]),
        ]);
    }

    public function test_sync_writes_exactly_one_audit_row_with_the_facts(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->syncer()->sync($invoice, $actor, '  '.self::REASON.'  ');

        $this->assertSame(1, BillingAuditLog::count());
        $log = BillingAuditLog::first();
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($invoice->id, $log->invoice_id);
        $this->assertSame($invoice->organization_id, $log->organization_id);
        $this->assertSame($invoice->invoice_number, $log->invoice_number);
        $this->assertSame(BillingAuditLog::ACTION_SYNC, $log->action);
        $this->assertSame(SyncOutcome::PAID, $log->result);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame('pending', $log->old_status);
        $this->assertSame('paid', $log->new_status);
        $this->assertNotNull($log->created_at);
        $this->assertSame(1, Subscription::count());
    }

    public function test_check_writes_one_audit_row_without_a_reason(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'PENDING');

        $this->syncer()->check($invoice, $actor);

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_CHECK_STATUS, $log->action);
        $this->assertSame('PENDING', $log->result);
        $this->assertNull($log->reason);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame('pending', $log->old_status);
        $this->assertSame('pending', $log->new_status);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_check_audits_an_unreachable_doku_and_still_throws(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        try {
            $this->syncer()->check($invoice, $actor);
            $this->fail('Expected DokuPaymentException.');
        } catch (\App\Services\Doku\DokuPaymentException) {
            $this->assertSame(SyncOutcome::DOKU_UNREACHABLE, BillingAuditLog::sole()->result);
        }
    }

    public function test_check_audits_a_response_about_another_invoice(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        Http::fake(['*' => Http::response([
            'order' => ['invoice_number' => 'INV-SOMEONE-ELSE', 'amount' => 1],
            'transaction' => ['status' => 'SUCCESS'],
        ])]);

        $this->syncer()->check($invoice, $actor);

        $this->assertSame(SyncOutcome::INVOICE_MISMATCH, BillingAuditLog::sole()->result);
    }

    public function test_no_actor_writes_no_audit_row(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->syncer()->check($invoice);
        $this->syncer()->sync($invoice);

        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_every_sync_outcome_with_an_actor_is_audited_once(): void
    {
        $actor = User::factory()->create();

        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())->create(['created_at' => now()->subHour()]);
        $young = $this->pendingInvoice();
        $young->forceFill(['created_at' => now()->subSeconds(10)])->save();
        $mismatch = $this->pendingInvoice();
        $this->fakeStatus($mismatch, 'SUCCESS', amount: 1);
        $notForwarded = $this->pendingInvoice();

        Http::fake([
            self::BASE.'/orders/v1/status/'.$notForwarded->invoice_number => Http::response([
                'order' => ['invoice_number' => $notForwarded->invoice_number, 'amount' => $notForwarded->amount],
                'transaction' => ['status' => 'PENDING'],
            ]),
            self::BASE.'/orders/v1/status/'.$mismatch->invoice_number => Http::response([
                'order' => ['invoice_number' => $mismatch->invoice_number, 'amount' => 1],
                'transaction' => ['status' => 'SUCCESS', 'original_request_id' => $mismatch->doku_request_id],
            ]),
        ]);

        foreach ([$paid, $young, $mismatch, $notForwarded] as $invoice) {
            $this->syncer()->sync($invoice, $actor, self::REASON);
        }

        $this->assertEqualsCanonicalizing(
            [SyncOutcome::ALREADY_PROCESSED, SyncOutcome::TOO_EARLY, SyncOutcome::AMOUNT_MISMATCH, SyncOutcome::NOT_FORWARDED],
            BillingAuditLog::pluck('result')->all()
        );
        $this->assertSame(4, BillingAuditLog::where('action', BillingAuditLog::ACTION_SYNC)->count());
    }

    public function test_apply_failed_is_still_audited_after_the_transaction_rolls_back(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->mock(SubscriptionActivationService::class, function ($mock) {
            $mock->shouldReceive('process')->once()->andThrow(new \RuntimeException('SQLSTATE secret detail'));
        });

        $outcome = $this->syncer()->sync($invoice, $actor, self::REASON);

        $this->assertSame(SyncOutcome::APPLY_FAILED, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $log = BillingAuditLog::sole();
        $this->assertSame(SyncOutcome::APPLY_FAILED, $log->result);
        $this->assertSame('pending', $log->old_status);
        $this->assertSame('pending', $log->new_status);
        $this->assertStringNotContainsString('secret detail', json_encode($log->getAttributes()));
    }

    public function test_a_failing_audit_write_rolls_back_the_mutation_instead_of_leaving_it_unaudited(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        // Make every audit insert fail; the sync must not commit unaudited.
        BillingAuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $outcome = $this->syncer()->sync($invoice, $actor, self::REASON);

        $this->assertSame(SyncOutcome::APPLY_FAILED, $outcome->result);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_an_audit_row_stores_nothing_sensitive(): void
    {
        $actor = User::factory()->create();
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->syncer()->check($invoice, $actor);
        $this->syncer()->sync($invoice, $actor, self::REASON);

        $this->assertSame(2, BillingAuditLog::count());

        $allowed = ['id', 'actor_user_id', 'invoice_id', 'organization_id', 'invoice_number', 'action', 'result', 'reason', 'old_status', 'new_status', 'created_at'];
        $this->assertEqualsCanonicalizing($allowed, Schema::getColumnListing('billing_audit_logs'));

        foreach (BillingAuditLog::all() as $log) {
            $this->assertEqualsCanonicalizing($allowed, array_keys($log->getAttributes()));
            $dump = json_encode($log->getAttributes());
            foreach (['URL-SENTINEL', 'TOKEN-SENTINEL', 'SIGNATURE-SENTINEL', 'test-secret-key', $invoice->doku_request_id, $invoice->doku_token_id, $invoice->doku_payment_url] as $secret) {
                $this->assertStringNotContainsString($secret, $dump);
            }
        }
    }
}

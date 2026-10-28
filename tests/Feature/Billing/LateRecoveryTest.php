<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceSyncer;
use App\Services\SubscriptionActivationService;
use App\Services\SyncOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pembayaran telat Tahap B: invoice yang sudah `expired` di sini tetapi
 * dibayar di DOKU HANYA bisa dipulihkan lewat aksi admin eksplisit
 * (InvoiceSyncer::recoverLate -> process($payload, true)). Webhook dan
 * polling tidak pernah memulihkan (lihat test CHARACTERIZATION di
 * PaymentLifecycleTest dan DokuWebhookTest).
 */
class LateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.doku.com';

    private const REASON = 'Pelanggan membayar setelah kedaluwarsa, bukti transfer ada';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doku.client_id' => 'MCH-0001-TEST',
            'doku.secret_key' => 'test-secret-key',
            'doku.production' => false,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::firstOrCreate(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    private function service(): SubscriptionActivationService
    {
        return app(SubscriptionActivationService::class);
    }

    private function syncer(): InvoiceSyncer
    {
        return app(InvoiceSyncer::class);
    }

    private function expiredInvoice(array $attrs = [], ?Organization $organization = null): Invoice
    {
        return Invoice::factory()->for($organization ?? Organization::factory()->create())->create(array_merge([
            'status' => Invoice::STATUS_EXPIRED,
            'expires_at' => now()->subDays(2),
            'created_at' => now()->subDays(3),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice, string $status = 'SUCCESS', ?int $amount = null, ?string $requestId = null, ?string $number = null): array
    {
        return [
            'order' => ['invoice_number' => $number ?? $invoice->invoice_number, 'amount' => $amount ?? $invoice->amount],
            'transaction' => ['status' => $status, 'original_request_id' => $requestId ?? $invoice->doku_request_id],
            'payment' => ['url' => 'https://evil.example/URL-SENTINEL', 'token_id' => 'TOKEN-SENTINEL'],
            'signature' => 'SIGNATURE-SENTINEL',
        ];
    }

    private function fakeStatus(Invoice $invoice, string $status = 'SUCCESS', ?int $amount = null): void
    {
        Http::fake([self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response($this->payload($invoice, $status, $amount))]);
    }

    // ------------------------------------------------ process(): default unchanged

    public function test_process_by_default_still_refuses_an_expired_invoice_with_success(): void
    {
        $invoice = $this->expiredInvoice();

        $this->assertSame(SubscriptionActivationService::RESULT_ALREADY_PROCESSED, $this->service()->process($this->payload($invoice)));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Subscription::count());
    }

    public function test_the_webhook_and_polling_paths_never_pass_the_recovery_flag(): void
    {
        // The webhook hands over the payload only; the polling command goes through syncAsSystem().
        $this->assertStringContainsString('$activation->process($payload);', file_get_contents(app_path('Http/Controllers/DokuWebhookController.php')));
        $this->assertStringNotContainsString('->process(', file_get_contents(app_path('Console/Commands/SyncPendingInvoices.php')));
        $this->assertStringNotContainsString('recoverLate', file_get_contents(app_path('Console/Commands/SyncPendingInvoices.php')));

        // sync() / syncAsSystem() never allow recovery either.
        $source = file_get_contents(app_path('Services/InvoiceSyncer.php'));
        $this->assertSame(1, substr_count($source, 'process($payload, true)'));
    }

    // ------------------------------------------------ process($payload, true)

    public function test_recovery_pays_an_expired_invoice_and_creates_a_subscription_from_now(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->expiredInvoice(['duration_days' => 45]);

        $this->assertSame(SubscriptionActivationService::RESULT_RECOVERED, $this->service()->process($this->payload($invoice), true));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertTrue($invoice->paid_at->equalTo(now()));

        $subscription = Subscription::sole();
        $this->assertSame($invoice->organization_id, $subscription->organization_id);
        $this->assertTrue($subscription->starts_at->equalTo(now()), 'starts at recovery time, not at the DOKU payment time');
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(45)), 'frozen duration snapshot is used');
        $this->assertSame($subscription->id, $invoice->subscription_id);
    }

    public function test_recovery_extends_from_the_end_of_an_active_subscription(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->expiredInvoice(['duration_days' => 30]);
        $active = Subscription::create([
            'organization_id' => $invoice->organization_id,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(10),
        ]);

        $this->service()->process($this->payload($invoice), true);

        $this->assertSame(1, Subscription::count());
        $this->assertTrue($active->fresh()->ends_at->equalTo(now()->addDays(40)));
        $this->assertSame($active->id, $invoice->fresh()->subscription_id);
    }

    public function test_recovery_twice_activates_only_once(): void
    {
        $invoice = $this->expiredInvoice();

        $this->assertSame(SubscriptionActivationService::RESULT_RECOVERED, $this->service()->process($this->payload($invoice), true));
        $endsAt = Subscription::sole()->ends_at;

        $this->assertSame(SubscriptionActivationService::RESULT_ALREADY_PROCESSED, $this->service()->process($this->payload($invoice), true));

        $this->assertSame(1, Subscription::count());
        $this->assertTrue(Subscription::sole()->ends_at->equalTo($endsAt));
    }

    /**
     * @dataProvider refusedPayloads
     */
    public function test_recovery_still_applies_every_guard(string $kind, string $expectedResult): void
    {
        $invoice = $this->expiredInvoice();
        $payload = match ($kind) {
            'amount' => $this->payload($invoice, amount: $invoice->amount + 1),
            'request id' => $this->payload($invoice, requestId: 'another-request-id'),
            'unknown number' => $this->payload($invoice, number: 'INV-UNKNOWN'),
            'pending status' => $this->payload($invoice, 'PENDING'),
            'failed status' => $this->payload($invoice, 'FAILED'),
            'expired status' => $this->payload($invoice, 'EXPIRED'),
        };

        $this->assertSame($expectedResult, $this->service()->process($payload, true));

        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    /** @return array<string, array<int, string>> */
    public static function refusedPayloads(): array
    {
        return [
            'different amount' => ['amount', SubscriptionActivationService::RESULT_AMOUNT_MISMATCH],
            'different original_request_id' => ['request id', SubscriptionActivationService::RESULT_REFERENCE_MISMATCH],
            'unknown invoice number' => ['unknown number', SubscriptionActivationService::RESULT_UNKNOWN_INVOICE],
            'PENDING' => ['pending status', SubscriptionActivationService::RESULT_ALREADY_PROCESSED],
            'FAILED' => ['failed status', SubscriptionActivationService::RESULT_ALREADY_PROCESSED],
            'EXPIRED' => ['expired status', SubscriptionActivationService::RESULT_ALREADY_PROCESSED],
        ];
    }

    public function test_recovery_never_touches_paid_failed_or_pending_invoices(): void
    {
        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())->create();
        $failed = Invoice::factory()->failed()->for(Organization::factory()->create())->create();

        foreach ([$paid, $failed] as $invoice) {
            $this->assertSame(SubscriptionActivationService::RESULT_ALREADY_PROCESSED, $this->service()->process($this->payload($invoice), true));
        }
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(Invoice::STATUS_FAILED, $failed->fresh()->status);
        $this->assertSame(0, Subscription::count());

        // A pending invoice takes the ordinary path: the flag changes nothing for it.
        $pending = Invoice::factory()->for(Organization::factory()->create())->create();
        $this->assertSame(SubscriptionActivationService::RESULT_PAID, $this->service()->process($this->payload($pending), true));
    }

    public function test_recovery_refuses_an_invoice_expired_longer_ago_than_the_limit(): void
    {
        config(['subscriptions.late_recovery_max_days' => 30]);
        $tooOld = $this->expiredInvoice(['expires_at' => now()->subDays(31)]);
        $justInside = $this->expiredInvoice(['expires_at' => now()->subDays(29)]);

        $this->assertSame(SubscriptionActivationService::RESULT_RECOVERY_TOO_OLD, $this->service()->process($this->payload($tooOld), true));
        $this->assertSame(Invoice::STATUS_EXPIRED, $tooOld->fresh()->status);
        $this->assertSame(0, Subscription::where('organization_id', $tooOld->organization_id)->count());

        $this->assertSame(SubscriptionActivationService::RESULT_RECOVERED, $this->service()->process($this->payload($justInside), true));
    }

    public function test_the_age_limit_is_configurable_and_falls_back_to_updated_at(): void
    {
        config(['subscriptions.late_recovery_max_days' => 5]);
        $invoice = $this->expiredInvoice(['expires_at' => now()->subDays(6)]);
        $this->assertTrue($invoice->isTooOldToRecover());

        config(['subscriptions.late_recovery_max_days' => 7]);
        $this->assertFalse($invoice->isTooOldToRecover());

        $noExpiry = $this->expiredInvoice(['expires_at' => null]);
        $this->assertFalse($noExpiry->isTooOldToRecover(), 'updated_at (just now) is used');
    }

    // ------------------------------------------------ InvoiceSyncer::recoverLate

    public function test_only_a_super_admin_may_recover(): void
    {
        $invoice = $this->expiredInvoice();
        Http::fake();

        $this->expectException(AuthorizationException::class);

        try {
            $this->syncer()->recoverLate($invoice, User::factory()->create(), self::REASON);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, BillingAuditLog::count());
            $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        }
    }

    /**
     * @dataProvider badReasons
     */
    public function test_a_blank_or_too_long_reason_is_refused_before_any_http(string $reason): void
    {
        $invoice = $this->expiredInvoice();
        Http::fake();

        try {
            $this->syncer()->recoverLate($invoice, $this->superAdmin(), $reason);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
            $this->assertSame(0, BillingAuditLog::count());
            $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        }
    }

    /** @return array<string, array<int, string>> */
    public static function badReasons(): array
    {
        return ['empty' => [''], 'blank' => ['            '], 'too long' => [str_repeat('a', 501)]];
    }

    public function test_a_one_character_reason_is_accepted(): void
    {
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice);

        $this->assertSame(SyncOutcome::RECOVERED, $this->syncer()->recoverLate($invoice, $this->superAdmin(), 'x')->result);
        $this->assertSame('x', BillingAuditLog::sole()->reason);
    }

    public function test_recovery_pays_the_invoice_activates_once_and_audits_everything_needed(): void
    {
        $actor = $this->superAdmin();
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice);

        $outcome = $this->syncer()->recoverLate($invoice, $actor, '  '.self::REASON.'  ');

        $this->assertSame(SyncOutcome::RECOVERED, $outcome->result);
        $this->assertTrue($outcome->changedInvoice());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::count());

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_LATE_RECOVERY, $log->action);
        $this->assertSame('invoice.late_recovery', $log->action);
        $this->assertSame(SyncOutcome::RECOVERED, $log->result);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($invoice->id, $log->invoice_id);
        $this->assertSame($invoice->organization_id, $log->organization_id);
        $this->assertSame($invoice->invoice_number, $log->invoice_number);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame('expired', $log->old_status);
        $this->assertSame('paid', $log->new_status);

        $dump = json_encode($log->getAttributes());
        foreach (['URL-SENTINEL', 'TOKEN-SENTINEL', 'SIGNATURE-SENTINEL', 'test-secret-key', $invoice->doku_request_id, $invoice->doku_token_id, $invoice->doku_payment_url] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_a_second_recovery_is_already_processed_and_activates_nothing_more(): void
    {
        $actor = $this->superAdmin();
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice);

        $this->syncer()->recoverLate($invoice, $actor, self::REASON);
        Http::assertSentCount(1);

        $second = $this->syncer()->recoverLate($invoice, $actor, self::REASON);

        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $second->result);
        Http::assertSentCount(1);
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_non_expired_invoice_is_refused_without_http(): void
    {
        $actor = $this->superAdmin();
        $pending = Invoice::factory()->for(Organization::factory()->create())->create(['created_at' => now()->subHour()]);
        $failed = Invoice::factory()->failed()->for(Organization::factory()->create())->create(['created_at' => now()->subHour()]);
        Http::fake();

        $this->assertSame(SyncOutcome::NOT_RECOVERABLE, $this->syncer()->recoverLate($pending, $actor, self::REASON)->result);
        $this->assertSame(SyncOutcome::NOT_RECOVERABLE, $this->syncer()->recoverLate($failed, $actor, self::REASON)->result);

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(2, BillingAuditLog::where('action', BillingAuditLog::ACTION_LATE_RECOVERY)->count());
    }

    public function test_an_invoice_younger_than_60_seconds_is_refused_without_http(): void
    {
        $invoice = $this->expiredInvoice(['created_at' => now()->subSeconds(10)]);
        Http::fake();

        $this->assertSame(SyncOutcome::TOO_EARLY, $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON)->result);

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
    }

    public function test_an_invoice_expired_too_long_ago_is_refused_without_http_and_audited(): void
    {
        $invoice = $this->expiredInvoice(['expires_at' => now()->subDays(31)]);
        Http::fake();

        $outcome = $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON);

        $this->assertSame(SyncOutcome::RECOVERY_TOO_OLD, $outcome->result);
        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(SyncOutcome::RECOVERY_TOO_OLD, BillingAuditLog::sole()->result);
    }

    /**
     * @dataProvider nonSuccessStatuses
     */
    public function test_doku_not_saying_success_changes_nothing(string $dokuStatus): void
    {
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice, $dokuStatus);

        $outcome = $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON);

        $this->assertSame(SyncOutcome::NOT_FORWARDED, $outcome->result);
        $this->assertSame($dokuStatus, $outcome->dokuStatus);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame('expired', BillingAuditLog::sole()->new_status);
    }

    /** @return array<string, array<int, string>> */
    public static function nonSuccessStatuses(): array
    {
        return ['PENDING' => ['PENDING'], 'EXPIRED' => ['EXPIRED'], 'FAILED' => ['FAILED']];
    }

    public function test_an_unreachable_doku_changes_nothing(): void
    {
        $invoice = $this->expiredInvoice();
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        $outcome = $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON);

        $this->assertSame(SyncOutcome::DOKU_UNREACHABLE, $outcome->result);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_a_response_about_another_invoice_is_not_applied(): void
    {
        $invoice = $this->expiredInvoice();
        Http::fake(['*' => Http::response($this->payload($invoice, number: 'INV-SOMEONE-ELSE'))]);

        $this->assertSame(SyncOutcome::INVOICE_MISMATCH, $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON)->result);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
    }

    public function test_a_doku_amount_that_differs_is_not_applied(): void
    {
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice, amount: 1);

        $this->assertSame(SyncOutcome::AMOUNT_MISMATCH, $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON)->result);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_apply_failed_is_still_audited_after_the_rollback(): void
    {
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice);
        $this->mock(SubscriptionActivationService::class, function ($mock) {
            $mock->shouldReceive('process')->once()->with(\Mockery::type('array'), true)->andThrow(new \RuntimeException('SQLSTATE secret detail'));
        });

        $outcome = $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON);

        $this->assertSame(SyncOutcome::APPLY_FAILED, $outcome->result);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_LATE_RECOVERY, $log->action);
        $this->assertSame(SyncOutcome::APPLY_FAILED, $log->result);
        $this->assertSame('expired', $log->old_status);
        $this->assertSame('expired', $log->new_status);
        $this->assertStringNotContainsString('secret detail', json_encode($log->getAttributes()));
    }

    public function test_a_failing_audit_write_leaves_no_mutation(): void
    {
        $invoice = $this->expiredInvoice();
        $this->fakeStatus($invoice);
        BillingAuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $outcome = $this->syncer()->recoverLate($invoice, $this->superAdmin(), self::REASON);

        $this->assertSame(SyncOutcome::APPLY_FAILED, $outcome->result);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Subscription::count());
    }

    public function test_late_recovery_audit_rows_are_kept_for_365_days(): void
    {
        $this->assertSame(365, BillingAuditLog::RETENTION_DAYS[BillingAuditLog::ACTION_LATE_RECOVERY]);

        $old = BillingAuditLog::record(BillingAuditLog::ACTION_LATE_RECOVERY, 'recovered', null, null, self::REASON, 'expired', 'paid', 'INV-OLD');
        $old->forceFill(['created_at' => now()->subDays(366)])->save();
        $recent = BillingAuditLog::record(BillingAuditLog::ACTION_LATE_RECOVERY, 'recovered', null, null, self::REASON, 'expired', 'paid', 'INV-NEW');
        $recent->forceFill(['created_at' => now()->subDays(364)])->save();

        $this->assertSame([$old->id], (new BillingAuditLog)->prunable()->pluck('id')->all());
    }

    public function test_sync_and_system_sync_cannot_recover_an_expired_invoice(): void
    {
        $invoice = $this->expiredInvoice();
        Http::fake();

        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $this->syncer()->sync($invoice, $this->superAdmin(), self::REASON)->result);
        $this->assertSame(SyncOutcome::ALREADY_PROCESSED, $this->syncer()->syncAsSystem($invoice)->result);

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
    }
}

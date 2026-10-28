<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Doku\DokuSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Siklus pembayaran DOKU end-to-end lintas jalur (webhook + polling +
 * expire command). Test per-jalur ada di DokuWebhookTest dan
 * SyncPendingInvoicesTest; file ini hanya menutup skenario yang
 * MELINTASI jalur: webhook dan polling untuk pembayaran yang sama,
 * status tidak berurutan, pembayaran terlambat, kegagalan DB, dan
 * jadwal scheduler.
 *
 * Test bertanda "CHARACTERIZATION" mendokumentasikan perilaku webhook dan
 * polling untuk bayar terlambat (SUCCESS setelah invoice ditutup lokal):
 * keputusan bisnis (Tahap B) = keduanya TETAP tidak memulihkan secara
 * otomatis; pemulihan hanya lewat aksi admin eksplisit "Pulihkan
 * pembayaran" (InvoiceSyncer::recoverLate, diuji di LateRecoveryTest).
 * Jika keputusan itu berubah, test-test ini yang harus diubah bersamaan.
 */
class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'MCH-0001-TEST';

    private const SECRET_KEY = 'test-secret-key';

    private const PATH = '/api/doku/notifications';

    private const BASE = 'https://api-sandbox.doku.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doku.client_id' => self::CLIENT_ID,
            'doku.secret_key' => self::SECRET_KEY,
            'doku.production' => false,
        ]);
    }

    private function pendingInvoice(array $attrs = [], ?Organization $organization = null): Invoice
    {
        return Invoice::factory()->for($organization ?? Organization::factory()->create())->create(array_merge([
            'created_at' => now()->subMinutes(10),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice, string $status, ?int $amount = null): array
    {
        return [
            'order' => [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $amount ?? $invoice->amount,
            ],
            'transaction' => [
                'status' => $status,
                'original_request_id' => $invoice->doku_request_id,
            ],
        ];
    }

    private function webhook(array $payload)
    {
        $rawBody = json_encode($payload);
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $component = DokuSignature::componentString(
            self::CLIENT_ID, $requestId, $timestamp, self::PATH, DokuSignature::digest($rawBody)
        );

        $headers = [
            'Client-Id' => self::CLIENT_ID,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => DokuSignature::sign($component, self::SECRET_KEY),
            'Content-Type' => 'application/json',
        ];

        return $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars($headers), $rawBody);
    }

    private function fakeStatus(Invoice $invoice, string $status, ?int $amount = null): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response($this->payload($invoice, $status, $amount)),
        ]);
    }

    private function poll(): void
    {
        $this->artisan('subscriptions:sync-pending-invoices')->assertSuccessful();
    }

    // ------------------------------------------------ A. sukses, tepat sekali

    public function test_success_via_webhook_sets_paid_at_and_activates_once(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->pendingInvoice();

        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertOk()->assertJson(['message' => 'paid']);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(now(), $invoice->paid_at);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertSame(1, Subscription::count());
    }

    public function test_success_via_polling_when_the_webhook_never_arrived(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->poll();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertTrue($invoice->organization->isSubscribed());
        $this->assertSame(1, Subscription::count());
    }

    public function test_webhook_then_polling_for_the_same_payment_activates_only_once(): void
    {
        $invoice = $this->pendingInvoice();

        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertOk();
        $endsAt = Subscription::sole()->ends_at;

        // Setelah paid, invoice tidak lagi masuk antrean polling.
        Http::fake();
        $this->poll();
        Http::assertNothingSent();

        $this->assertSame(1, Subscription::count());
        $this->assertEquals($endsAt, Subscription::sole()->ends_at);
    }

    public function test_polling_then_late_webhook_for_the_same_payment_activates_only_once(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'SUCCESS');
        $this->poll();
        $endsAt = Subscription::sole()->ends_at;

        $this->webhook($this->payload($invoice, 'SUCCESS'))
            ->assertOk()->assertJson(['message' => 'already_processed']);

        $this->assertSame(1, Subscription::count());
        $this->assertEquals($endsAt, Subscription::sole()->ends_at);
    }

    public function test_renewal_is_extended_exactly_once_even_if_webhook_and_polling_both_report_success(): void
    {
        $this->travelTo(now()->startOfSecond());
        $organization = Organization::factory()->create();
        $existing = Subscription::create([
            'organization_id' => $organization->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
        ]);
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly'], $organization);
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertOk();
        $afterWebhook = $existing->fresh()->ends_at;
        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertJson(['message' => 'already_processed']);
        $this->poll();

        $this->assertEquals(now()->addDays(10 + 30), $afterWebhook);
        $this->assertEquals($afterWebhook, $existing->fresh()->ends_at);
        $this->assertSame(1, Subscription::count());
    }

    // ------------------------------------------------ B/C. gagal & pending

    public function test_failed_never_pays_or_activates_and_a_later_success_still_works(): void
    {
        $invoice = $this->pendingInvoice();

        $this->webhook($this->payload($invoice, 'FAILED'))->assertOk()->assertJson(['message' => 'ignored']);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());

        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertOk()->assertJson(['message' => 'paid']);
        $this->assertSame(1, Subscription::count());
    }

    public function test_repeated_pending_polls_never_fail_or_activate_and_keep_polling(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'PENDING');

        $this->poll();
        $this->poll();
        $this->poll();

        Http::assertSentCount(3);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_timeout_then_recovery_on_a_later_poll(): void
    {
        $invoice = $this->pendingInvoice();
        $reachable = false;
        Http::fake(function () use (&$reachable, $invoice) {
            if (! $reachable) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out');
            }

            return Http::response($this->payload($invoice, 'SUCCESS'));
        });
        $this->poll();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);

        $reachable = true;
        $this->poll();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::count());
    }

    public function test_malformed_status_response_leaves_the_invoice_pending_and_does_not_stop_the_run(): void
    {
        $malformed = $this->pendingInvoice();
        $good = $this->pendingInvoice();
        Http::fake([
            self::BASE.'/orders/v1/status/'.$malformed->invoice_number => Http::response(['unexpected' => true]),
            self::BASE.'/orders/v1/status/'.$good->invoice_number => Http::response($this->payload($good, 'SUCCESS')),
        ]);

        $this->poll();

        $this->assertSame(Invoice::STATUS_PENDING, $malformed->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $good->fresh()->status);
    }

    public function test_status_response_for_a_different_invoice_number_is_not_applied(): void
    {
        $asked = $this->pendingInvoice();
        $other = $this->pendingInvoice();
        Http::fake([
            self::BASE.'/orders/v1/status/'.$asked->invoice_number => Http::response($this->payload($other, 'SUCCESS')),
        ]);

        $this->artisan('subscriptions:sync-pending-invoices', ['--invoice' => $asked->invoice_number])->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PENDING, $asked->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $other->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_wrong_amount_leaves_the_invoice_pending_via_both_paths(): void
    {
        $viaWebhook = $this->pendingInvoice(['amount' => 150000]);
        $viaPolling = $this->pendingInvoice(['amount' => 150000]);

        $this->webhook($this->payload($viaWebhook, 'SUCCESS', 1000))->assertJson(['message' => 'amount_mismatch']);
        $this->fakeStatus($viaPolling, 'SUCCESS', 1000);
        $this->artisan('subscriptions:sync-pending-invoices', ['--invoice' => $viaPolling->invoice_number]);

        $this->assertSame(Invoice::STATUS_PENDING, $viaWebhook->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $viaPolling->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    // ------------------------------------------------ E. kedaluwarsa

    public function test_expired_at_doku_is_final_and_final_invoices_are_not_polled_again(): void
    {
        $invoice = $this->pendingInvoice();
        $this->fakeStatus($invoice, 'EXPIRED');
        $this->poll();

        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());

        Http::fake();
        $this->poll();
        Http::assertNothingSent();
    }

    // ------------------------------------------------ F. tidak berurutan

    public function test_no_regression_from_paid_whatever_arrives_later(): void
    {
        $invoice = $this->pendingInvoice();
        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertOk();

        foreach (['PENDING', 'FAILED', 'EXPIRED', 'REFUNDED', 'TIMEOUT', 'SUCCESS'] as $status) {
            $this->webhook($this->payload($invoice, $status))->assertJson(['message' => 'already_processed']);
            $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, $status);
        }

        $this->assertSame(1, Subscription::count());
    }

    public function test_an_expired_invoice_is_not_reactivated_by_a_duplicate_expired_or_old_pending_webhook(): void
    {
        $invoice = $this->pendingInvoice();
        $this->webhook($this->payload($invoice, 'EXPIRED'))->assertJson(['message' => 'expired']);

        foreach (['EXPIRED', 'PENDING', 'FAILED'] as $status) {
            $this->webhook($this->payload($invoice, $status))->assertJson(['message' => 'already_processed']);
        }

        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_an_invalid_signature_never_changes_state_even_after_a_valid_success(): void
    {
        $invoice = $this->pendingInvoice();

        $rawBody = json_encode($this->payload($invoice, 'SUCCESS'));
        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([
            'Client-Id' => self::CLIENT_ID,
            'Request-Id' => (string) Str::uuid(),
            'Request-Timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'Signature' => 'HMACSHA256=forged',
            'Content-Type' => 'application/json',
        ]), $rawBody)->assertStatus(400);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    // ------------------------------------------------ D. terlambat

    public function test_success_confirmed_after_the_local_expires_at_but_before_the_expire_command_still_pays(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->pendingInvoice(['expires_at' => now()->addMinutes(5)]);

        $this->travel(15)->minutes(); // 10 menit lewat expires_at lokal, di dalam masa tenggang 30 menit
        $this->artisan('subscriptions:expire-invoices')->assertSuccessful();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);

        $this->fakeStatus($invoice, 'SUCCESS');
        $this->poll();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertSame(1, Subscription::count());
    }

    public function test_late_webhook_inside_the_grace_window_pays_and_a_following_poll_adds_nothing(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->pendingInvoice(['expires_at' => now()->addMinutes(5)]);
        $this->travel(20)->minutes();

        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertJson(['message' => 'paid']);

        Http::fake();
        $this->poll();

        $this->assertSame(1, Subscription::count());
    }

    /**
     * CHARACTERIZATION (keputusan: webhook/polling tidak memulihkan otomatis;
     * pemulihan hanya lewat aksi admin, lihat LateRecoveryTest).
     * Setelah masa tenggang, expire command menutup invoice lokal sebagai
     * expired. Jika DOKU ternyata SUKSES menerima uang sesudahnya, webhook
     * dijawab already_processed, polling tidak lagi memeriksa invoice itu,
     * dan tidak ada Subscription/jejak DB: uang masuk tanpa akses sampai admin
     * memulihkannya secara manual.
     */
    public function test_characterization_success_after_the_invoice_was_closed_locally_is_dropped(): void
    {
        $this->travelTo(now()->startOfSecond());
        $invoice = $this->pendingInvoice(['expires_at' => now()->addMinutes(5)]);

        $this->travel(60)->minutes();
        $this->artisan('subscriptions:expire-invoices')->assertSuccessful();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);

        $this->webhook($this->payload($invoice, 'SUCCESS'))
            ->assertOk()->assertJson(['message' => 'already_processed']);

        Http::fake();
        $this->poll();
        Http::assertNothingSent();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Subscription::count());
    }

    /** CHARACTERIZATION: invoice di luar jendela polling tidak ditanyakan ke DOKU. */
    public function test_characterization_polling_skips_pending_invoices_beyond_the_grace_window(): void
    {
        $invoice = $this->pendingInvoice(['expires_at' => now()->subHours(2)]);
        Http::fake();

        $this->poll();

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    // ------------------------------------------------ kegagalan DB

    public function test_a_db_failure_during_activation_rolls_back_via_webhook_and_a_retry_activates_once(): void
    {
        $invoice = $this->pendingInvoice();
        $fail = true;
        Subscription::creating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('simulated DB failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->webhook($this->payload($invoice, 'SUCCESS'));
            $this->fail('exception expected');
        } catch (\RuntimeException) {
            // di produksi menjadi HTTP 500 sehingga DOKU mengirim ulang notifikasi
        }

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status, 'transaksi harus rollback');
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Subscription::count());

        $fail = false;
        $this->webhook($this->payload($invoice, 'SUCCESS'))->assertJson(['message' => 'paid']);
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_db_failure_on_one_invoice_during_polling_does_not_stop_the_others(): void
    {
        $broken = $this->pendingInvoice();
        $good = $this->pendingInvoice();
        Subscription::creating(function (Subscription $subscription) use ($broken) {
            if ($subscription->organization_id === $broken->organization_id) {
                throw new \RuntimeException('simulated DB failure');
            }
        });
        Http::fake([
            self::BASE.'/orders/v1/status/'.$broken->invoice_number => Http::response($this->payload($broken, 'SUCCESS')),
            self::BASE.'/orders/v1/status/'.$good->invoice_number => Http::response($this->payload($good, 'SUCCESS')),
        ]);

        $this->poll();

        $this->assertSame(Invoice::STATUS_PENDING, $broken->fresh()->status);
        $this->assertNull($broken->fresh()->paid_at);
        $this->assertSame(Invoice::STATUS_PAID, $good->fresh()->status);
        $this->assertSame(1, Subscription::count());
    }

    // ------------------------------------------------ scheduler

    public function test_scheduler_registers_polling_and_expiry_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        $sync = $events->first(fn ($e) => str_contains($e->command, 'subscriptions:sync-pending-invoices'));
        $expire = $events->first(fn ($e) => str_contains($e->command, 'subscriptions:expire-invoices'));

        $this->assertNotNull($sync, 'polling belum terdaftar di scheduler');
        $this->assertNotNull($expire, 'expire command belum terdaftar di scheduler');
        $this->assertSame('*/5 * * * *', $sync->expression);
        $this->assertSame('0 * * * *', $expire->expression);
        $this->assertTrue($sync->withoutOverlapping);
        $this->assertTrue($expire->withoutOverlapping);
    }
}

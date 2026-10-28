<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\PlatformPayments;
use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceSyncer;
use App\Services\StatusSnapshot;
use App\Services\SyncOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Super Admin row actions on "Pembayaran": check status, sync, history.
 * Fake DOKU bodies are the REAL flat check-status shape (no "response"
 * wrapper), plus fields an operator-facing screen must never echo.
 */
class PlatformPaymentsActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.doku.com';

    private const REASON = 'Pelanggan sudah membayar tetapi webhook tidak masuk';

    /** Strings that must never reach HTML, notifications or Livewire state. */
    private const SENTINELS = ['URL-SENTINEL', 'TOKEN-SENTINEL', 'SIGNATURE-SENTINEL', 'test-secret-key', 'audit down', 'SQLSTATE'];

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

    private function invoice(array $attrs = []): Invoice
    {
        return Invoice::factory()->for(Organization::factory()->create())->create($attrs + [
            'created_at' => now()->subMinutes(10),
        ]);
    }

    /** @return array<string, mixed> */
    private function body(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null, ?string $number = null): array
    {
        return [
            'order' => [
                'invoice_number' => $number ?? $invoice->invoice_number,
                'amount' => $amount ?? $invoice->amount,
                'status' => 'ORDER_'.$status,
            ],
            'transaction' => array_merge(['status' => $status, 'original_request_id' => $invoice->doku_request_id], $transaction),
            'service' => ['id' => 'VIRTUAL_ACCOUNT'],
            'acquirer' => ['id' => 'BCA', 'name' => 'BCA'],
            'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA'],
            'payment' => ['url' => 'https://evil.example/URL-SENTINEL', 'token_id' => 'TOKEN-SENTINEL'],
            'signature' => 'SIGNATURE-SENTINEL',
        ];
    }

    private function fakeStatus(Invoice $invoice, string $status, array $transaction = [], ?int $amount = null): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response($this->body($invoice, $status, $transaction, $amount)),
        ]);
    }

    /** @return array{title: string, body: string}|null */
    private function lastNotification(): ?array
    {
        $all = session('filament.notifications') ?? [];
        $last = end($all);

        return $last ? ['title' => (string) ($last['title'] ?? ''), 'body' => (string) ($last['body'] ?? '')] : null;
    }

    private function assertNothingSensitive(string $haystack): void
    {
        foreach (self::SENTINELS as $needle) {
            $this->assertStringNotContainsString($needle, $haystack);
        }
    }

    private function sync($page, Invoice $invoice, string $reason = self::REASON)
    {
        return $page->callTableAction('syncInvoice', $invoice, ['reason' => $reason]);
    }

    /** Drives the action without the visibility assertion, like a hand-made Livewire request. */
    private function craftedSync($page, Invoice $invoice)
    {
        return $page->call('mountTableAction', 'syncInvoice', (string) $invoice->id)
            ->set('mountedTableActionData.reason', self::REASON)
            ->call('callMountedTableAction');
    }

    // ------------------------------------------------------ authorization

    public function test_every_action_is_forbidden_for_everyone_but_a_super_admin(): void
    {
        $invoice = $this->invoice();
        Http::fake();

        $organization = Organization::factory()->create();

        foreach (['owner', 'admin', 'member', null] as $role) {
            $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

            $user = User::factory()->create();
            if ($role !== null) {
                $organization->users()->attach($user->id, ['role' => $role]);
            }
            $this->actingAs($user);

            $page->call('mountTableAction', 'checkStatus', (string) $invoice->id)->assertForbidden();
            $page->call('mountTableAction', 'syncInvoice', (string) $invoice->id)->assertForbidden();
            $page->call('mountTableAction', 'recoverLatePayment', (string) $invoice->id)->assertForbidden();
            $page->call('mountTableAction', 'history', (string) $invoice->id)->assertForbidden();
            $page->call('callMountedTableAction')->assertForbidden();
        }

        $this->assertSame(0, BillingAuditLog::count());
        Http::assertNothingSent();
    }

    public function test_the_action_closures_recheck_super_admin_even_if_boot_were_bypassed(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformPayments.php'));

        $this->assertSame(4, substr_count($source, 'abort_unless(static::userCanAccessPage(), 403);'), 'runCheckStatus, runSync, runRecoverLate and historyView each re-check.');
    }

    // ------------------------------------------------------ visible layout

    public function test_the_three_actions_are_labelled_icons_in_the_last_column_and_the_page_has_a_title(): void
    {
        $pending = $this->invoice(['invoice_number' => 'INV-PENDING-1']);
        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())->create(['invoice_number' => 'INV-PAID-1']);

        $html = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->lastRenderedDom;

        // Title in the page header, above the cards.
        $this->assertStringContainsString('filament-header-heading', $html);
        $this->assertMatchesRegularExpression('/filament-header-heading[^>]*>\s*Pembayaran\s*</', $html);
        $this->assertLessThan(strpos($html, 'rk-pl__stats'), strpos($html, 'filament-header-heading'));

        // Icons are in the row markup itself (no dropdown to open), each named for assistive tech.
        $rows = preg_split('/<tr\b/', $html);
        $pendingRow = collect($rows)->first(fn ($r) => str_contains($r, 'INV-PENDING-1'));
        $paidRow = collect($rows)->first(fn ($r) => str_contains($r, 'INV-PAID-1'));

        foreach (['Cek status', 'Sinkronkan', 'Riwayat'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $pendingRow);
        }
        $this->assertStringNotContainsString('filament-dropdown', $pendingRow);
        $this->assertStringContainsString('rk-pp__act--sync', $pendingRow);
        $this->assertStringContainsString('filament-tables-icon-button-action', $pendingRow);

        // Last column, after the invoice number.
        $this->assertGreaterThan(strpos($pendingRow, 'INV-PENDING-1'), strpos($pendingRow, 'aria-label="Cek status"'));

        // A paid invoice only offers History; Sync is hidden, not greyed out.
        $this->assertStringContainsString('aria-label="Riwayat"', $paidRow);
        $this->assertStringNotContainsString('aria-label="Sinkronkan"', $paidRow);
        $this->assertStringNotContainsString('aria-label="Cek status"', $paidRow);

        // A legend explains the icons (including the recover icon).
        $this->assertStringContainsString('rk-pp__legend', $html);
        $this->assertStringContainsString('rk-pp__legend-recover', $html);
        $this->assertStringContainsString('Pulihkan pembayaran (hanya kedaluwarsa)', $html);

        // The column is labelled and the description no longer claims "read-only".
        $this->assertStringContainsString('Aksi', $html);
        $this->assertStringContainsString('DOKU hanya dihubungi saat Anda menekan', $html);
        $this->assertStringNotContainsString('baca-saja', $html);
    }

    // --------------------------------------------------------- check status

    public function test_check_status_shows_a_summary_audits_it_and_changes_nothing(): void
    {
        $actor = $this->superAdmin();
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');
        $before = DB::table('invoices')->get()->toArray();

        Livewire::actingAs($actor)->test(PlatformPayments::class)
            ->callTableAction('checkStatus', $invoice)
            ->assertHasNoTableActionErrors();

        $note = $this->lastNotification();
        $this->assertStringContainsString('Menunggu pembayaran', $note['body']);
        $this->assertStringContainsString('Rp 150.000', $note['body']);
        $this->assertStringContainsString('VIRTUAL ACCOUNT BCA', $note['body']);
        $this->assertStringContainsString('cocok', $note['body']);

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_CHECK_STATUS, $log->action);
        $this->assertSame('PENDING', $log->result);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($invoice->invoice_number, $log->invoice_number);

        $this->assertEquals($before, DB::table('invoices')->get()->toArray());
        $this->assertSame(0, Subscription::count());
    }

    public function test_check_status_labels_doku_transaction_date_as_order_time(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING', ['date' => '2026-10-26T01:02:03Z']);

        Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->callTableAction('checkStatus', $invoice);

        $body = $this->lastNotification()['body'];
        $this->assertStringContainsString('Waktu pesanan di DOKU', $body);
        $this->assertStringNotContainsString('Waktu transaksi DOKU', $body);
    }

    public function test_check_status_never_exposes_doku_secrets_in_html_notification_or_livewire_state(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->callTableAction('checkStatus', $invoice);

        $note = $this->lastNotification();
        $this->assertNothingSensitive($note['title'].$note['body']);
        $this->assertNothingSensitive($page->lastRenderedDom);
        $this->assertNothingSensitive(json_encode($page->payload));
        $this->assertStringNotContainsString($invoice->doku_request_id, $page->lastRenderedDom.json_encode($page->payload));
        $this->assertStringNotContainsString($invoice->doku_payment_url, $page->lastRenderedDom.json_encode($page->payload));
    }

    public function test_no_livewire_public_property_holds_a_record_or_a_doku_result(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->callTableAction('checkStatus', $invoice);

        foreach (get_object_vars($page->instance()) as $name => $value) {
            $this->assertNotInstanceOf(Model::class, $value, "public \${$name}");
            $this->assertNotInstanceOf(StatusSnapshot::class, $value, "public \${$name}");
            $this->assertNotInstanceOf(SyncOutcome::class, $value, "public \${$name}");
        }

        $state = json_encode($page->payload['serverMemo']['data']);
        $this->assertStringNotContainsString('VIRTUAL_ACCOUNT_BCA', $state);
        $this->assertNull($page->instance()->mountedTableActionRecord);
    }

    public function test_check_status_on_an_expired_invoice_reports_paid_at_doku_without_changing_anything(): void
    {
        $invoice = $this->invoice(['status' => Invoice::STATUS_EXPIRED, 'expires_at' => now()->subHour()]);
        $this->fakeStatus($invoice, 'SUCCESS');

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->assertTableActionVisible('checkStatus', $invoice)
            ->assertTableActionHidden('syncInvoice', $invoice)
            ->callTableAction('checkStatus', $invoice);

        $this->assertStringContainsString('sudah kedaluwarsa di sini', $this->lastNotification()['body']);
        $this->assertStringContainsString('Pulihkan pembayaran', $this->lastNotification()['body']);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame('SUCCESS', BillingAuditLog::sole()->result);
    }

    public function test_check_status_is_not_offered_for_paid_invoices(): void
    {
        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())->create();
        Http::fake();

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->assertTableActionHidden('checkStatus', $paid)
            ->assertTableActionHidden('syncInvoice', $paid)
            ->assertTableActionVisible('history', $paid);

        // A crafted call is refused server-side as well.
        $page->call('mountTableAction', 'checkStatus', (string) $paid->id)->call('callMountedTableAction');
        Http::assertNothingSent();
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_check_status_flags_a_response_about_another_invoice(): void
    {
        $invoice = $this->invoice();
        Http::fake(['*' => Http::response($this->body($invoice, 'SUCCESS', number: 'INV-SOMEONE-ELSE'))]);

        Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->callTableAction('checkStatus', $invoice);

        $this->assertStringContainsString('TIDAK COCOK', $this->lastNotification()['body']);
        $this->assertSame(SyncOutcome::INVOICE_MISMATCH, BillingAuditLog::sole()->result);
    }

    public function test_doku_failure_shows_a_generic_message_without_leaking_anything(): void
    {
        $invoice = $this->invoice();
        Http::fake(['*' => Http::response(['detail' => 'TOKEN-SENTINEL SQLSTATE URL-SENTINEL'], 500)]);

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->callTableAction('checkStatus', $invoice);

        $note = $this->lastNotification();
        $this->assertSame('DOKU tidak dapat dihubungi, coba lagi beberapa saat.', $note['body']);
        $this->assertNothingSensitive($note['body'].$page->lastRenderedDom.json_encode($page->payload));
        $this->assertSame(SyncOutcome::DOKU_UNREACHABLE, BillingAuditLog::sole()->result);
    }

    public function test_a_timeout_shows_the_same_generic_message(): void
    {
        $invoice = $this->invoice();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 test-secret-key')]);

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->callTableAction('checkStatus', $invoice);

        $this->assertSame('DOKU tidak dapat dihubungi, coba lagi beberapa saat.', $this->lastNotification()['body']);
        $this->assertNothingSensitive($page->lastRenderedDom.json_encode($page->payload));
    }

    public function test_a_failing_audit_write_on_check_gives_a_safe_message(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');
        BillingAuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)->callTableAction('checkStatus', $invoice);

        $note = $this->lastNotification();
        $this->assertStringContainsString('Terjadi kesalahan', $note['body']);
        $this->assertNothingSensitive($note['body'].$page->lastRenderedDom.json_encode($page->payload));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    // ----------------------------------------------------------------- sync

    public function test_sync_success_pays_the_invoice_creates_one_subscription_and_audits_with_the_reason(): void
    {
        $actor = $this->superAdmin();
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS');

        $this->sync(Livewire::actingAs($actor)->test(PlatformPayments::class), $invoice)
            ->assertHasNoTableActionErrors();

        $this->assertSame('Pembayaran dikonfirmasi, langganan diaktifkan.', $this->lastNotification()['body']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_SYNC, $log->action);
        $this->assertSame(SyncOutcome::PAID, $log->result);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame('pending', $log->old_status);
        $this->assertSame('paid', $log->new_status);
    }

    /**
     * @dataProvider badReasons
     */
    public function test_the_reason_is_required_and_between_10_and_500_characters(string $reason, string $rule): void
    {
        $invoice = $this->invoice();
        Http::fake();

        $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice, $reason)
            ->assertHasTableActionErrors(['reason']);

        Http::assertNothingSent();
        $this->assertSame(0, BillingAuditLog::count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    /** @return array<string, array<int, string>> */
    public static function badReasons(): array
    {
        return [
            'empty' => ['', 'required'],
            'short' => ['terlalu', 'min'],
            'padded short' => ['      x         ', 'min'],
            'too long' => [str_repeat('a', 501), 'max'],
        ];
    }

    public function test_sync_expired_closes_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'EXPIRED');

        $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertSame('DOKU menyatakan invoice kedaluwarsa, invoice ditutup.', $this->lastNotification()['body']);
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_with_a_pending_status_at_doku_keeps_the_invoice_pending(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');

        $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertSame('Belum ada hasil final di DOKU, invoice tetap menunggu.', $this->lastNotification()['body']);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(SyncOutcome::NOT_FORWARDED, BillingAuditLog::sole()->result);
    }

    public function test_sync_with_an_amount_mismatch_applies_nothing(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS', amount: 1);

        $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertStringContainsString('tidak cocok dengan invoice', $this->lastNotification()['body']);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_of_a_non_pending_invoice_makes_no_http_call(): void
    {
        Http::fake();
        $expired = $this->invoice(['status' => Invoice::STATUS_EXPIRED]);

        // The action is hidden for it; call it anyway like a crafted request would.
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'syncInvoice', (string) $expired->id)
            ->set('mountedTableActionData.reason', self::REASON)
            ->call('callMountedTableAction');

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_sync_of_a_too_young_invoice_is_refused_without_http(): void
    {
        Http::fake();
        $invoice = $this->invoice(['created_at' => now()->subSeconds(20)]);

        $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertStringContainsString('Terlalu cepat, tunggu minimal 60 detik', $this->lastNotification()['body']);
        Http::assertNothingSent();
        $this->assertSame(SyncOutcome::TOO_EARLY, BillingAuditLog::sole()->result);
    }

    public function test_sync_doku_failure_is_generic_and_leaves_the_invoice(): void
    {
        $invoice = $this->invoice();
        Http::fake(['*' => Http::response(['detail' => 'TOKEN-SENTINEL'], 500)]);

        $page = $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertSame('DOKU tidak dapat dihubungi, coba lagi beberapa saat.', $this->lastNotification()['body']);
        $this->assertNothingSensitive($page->lastRenderedDom.json_encode($page->payload));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_a_failing_audit_write_on_sync_mutates_nothing_and_shows_a_safe_message(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS');
        BillingAuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $page = $this->sync(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertSame('Gagal menerapkan, invoice tidak berubah.', $this->lastNotification()['body']);
        $this->assertNothingSensitive($page->lastRenderedDom.json_encode($page->payload));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_pressing_sync_twice_activates_only_once(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'SUCCESS');
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        $this->sync($page, $invoice);
        $this->craftedSync($page, $invoice); // throttled
        $this->travel(31)->seconds();
        // Allowed again, but the invoice is final: the button is gone, so drive it like a crafted request.
        $this->craftedSync($page, $invoice);

        $this->assertSame(1, Subscription::where('organization_id', $invoice->organization_id)->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        // The throttled click and the crafted call on a final invoice (Filament refuses a hidden action) left no extra audit rows.
        $this->assertSame([SyncOutcome::PAID], BillingAuditLog::pluck('result')->all());
    }

    public function test_a_vanished_invoice_gives_a_safe_message_and_no_http(): void
    {
        Http::fake();
        $invoice = $this->invoice();
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'syncInvoice', (string) $invoice->id)
            ->set('mountedTableActionData.reason', self::REASON);

        $invoice->delete();
        $page->call('callMountedTableAction');

        Http::assertNothingSent();
        $this->assertSame(0, BillingAuditLog::count());

        // And a made-up id never reaches DOKU either.
        Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'checkStatus', '99999999')
            ->call('callMountedTableAction');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- throttle

    public function test_the_same_action_twice_on_one_invoice_within_30_seconds_is_throttled(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        $page->callTableAction('checkStatus', $invoice);
        Http::assertSentCount(1);

        $page->callTableAction('checkStatus', $invoice);
        $this->assertMatchesRegularExpression('/^Terlalu sering, coba lagi dalam \d+ detik\.$/', $this->lastNotification()['body']);
        Http::assertSentCount(1);
        $this->assertSame(1, BillingAuditLog::count(), 'A throttled click writes no audit row.');

        $this->travel(31)->seconds();
        $page->callTableAction('checkStatus', $invoice);
        Http::assertSentCount(2);
    }

    public function test_sync_twice_on_one_invoice_within_30_seconds_is_throttled(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        $this->sync($page, $invoice);
        Http::assertSentCount(1);

        $this->sync($page, $invoice);
        $this->assertMatchesRegularExpression('/^Terlalu sering/', $this->lastNotification()['body']);
        Http::assertSentCount(1);
        $this->assertSame(1, BillingAuditLog::count());
    }

    public function test_check_status_does_not_hold_back_sync_on_the_same_invoice_and_vice_versa(): void
    {
        $invoice = $this->invoice();
        $this->fakeStatus($invoice, 'PENDING');
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        $page->callTableAction('checkStatus', $invoice);
        $this->sync($page, $invoice);

        Http::assertSentCount(2);
        $this->assertEqualsCanonicalizing(
            [BillingAuditLog::ACTION_CHECK_STATUS, BillingAuditLog::ACTION_SYNC],
            BillingAuditLog::pluck('action')->all()
        );

        // Each action is still held back by its own earlier call.
        $page->callTableAction('checkStatus', $invoice);
        $this->assertStringContainsString('Terlalu sering', $this->lastNotification()['body']);
        $this->sync($page, $invoice);
        $this->assertStringContainsString('Terlalu sering', $this->lastNotification()['body']);
        Http::assertSentCount(2);
    }

    public function test_the_per_user_budget_is_shared_by_check_and_sync(): void
    {
        $invoices = collect(range(1, 11))->map(fn () => $this->invoice());
        Http::fake(function (Request $request) use ($invoices) {
            $number = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->body($invoices->firstWhere('invoice_number', $number), 'PENDING'));
        });
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        foreach ($invoices->take(10) as $invoice) {
            $page->callTableAction('checkStatus', $invoice);
            $this->sync($page, $invoice);
        }
        Http::assertSentCount(20);

        $page->callTableAction('checkStatus', $invoices->last());
        $this->assertStringContainsString('Terlalu sering', $this->lastNotification()['body']);
        Http::assertSentCount(20);
    }

    public function test_a_user_is_limited_to_20_actions_per_minute(): void
    {
        $invoices = collect(range(1, 21))->map(fn () => $this->invoice());
        Http::fake(function (Request $request) use ($invoices) {
            $number = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->body($invoices->firstWhere('invoice_number', $number), 'PENDING'));
        });
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        foreach ($invoices->take(20) as $invoice) {
            $page->callTableAction('checkStatus', $invoice);
        }
        Http::assertSentCount(20);

        $page->callTableAction('checkStatus', $invoices->last());
        $this->assertStringContainsString('Terlalu sering', $this->lastNotification()['body']);
        Http::assertSentCount(20);
        $this->assertSame(20, BillingAuditLog::count());

        $this->travel(61)->seconds();
        $page->callTableAction('checkStatus', $invoices->last());
        Http::assertSentCount(21);
    }

    // -------------------------------------------------------------- history

    public function test_history_is_newest_first_with_actor_names_and_a_constant_query_count(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);
        $invoice = $this->invoice();
        $other = $this->invoice();

        $a = User::factory()->create(['name' => 'Operator Satu']);
        $b = User::factory()->create(['name' => 'Operator Dua']);
        BillingAuditLog::factory()->for($invoice)->create(['actor_user_id' => $a->id, 'result' => 'PENDING', 'created_at' => now()->subHours(2)]);
        BillingAuditLog::factory()->sync()->create(['invoice_id' => $invoice->id, 'actor_user_id' => $b->id, 'result' => 'paid', 'created_at' => now()->subHour(), 'reason' => 'Alasan terbaru ini']);
        BillingAuditLog::factory()->create(['invoice_id' => $other->id, 'reason' => 'MILIK INVOICE LAIN']);

        $count = function () use ($page, $invoice): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->call('mountTableAction', 'history', (string) $invoice->id);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(); // warm caches
        $few = $count();
        $page->assertSeeInOrder(['Operator Dua', 'Operator Satu'])
            ->assertSee('Alasan terbaru ini')
            ->assertDontSee('MILIK INVOICE LAIN');

        for ($i = 0; $i < 30; $i++) {
            BillingAuditLog::factory()->create(['invoice_id' => $invoice->id, 'actor_user_id' => User::factory()]);
        }

        $this->assertSame($few, $count(), 'History must not issue a query per row.');
    }

    public function test_history_shows_at_most_50_rows(): void
    {
        $invoice = $this->invoice();
        $actor = User::factory()->create();
        BillingAuditLog::factory()->count(55)->create(['invoice_id' => $invoice->id, 'actor_user_id' => $actor->id, 'reason' => 'baris-riwayat']);

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'history', (string) $invoice->id);

        $this->assertSame(50, substr_count($page->lastRenderedDom, 'baris-riwayat'));
    }

    public function test_history_escapes_the_reason(): void
    {
        $invoice = $this->invoice();
        BillingAuditLog::factory()->sync()->create(['invoice_id' => $invoice->id, 'reason' => '<script>alert("xss")</script> halo']);

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'history', (string) $invoice->id);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $page->lastRenderedDom);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $page->lastRenderedDom);
    }

    public function test_history_is_empty_state_for_an_untouched_invoice_and_keeps_rows_of_a_deleted_actor(): void
    {
        $invoice = $this->invoice();
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'history', (string) $invoice->id)
            ->assertSee('Belum ada tindakan penagihan');

        $log = BillingAuditLog::factory()->create(['invoice_id' => $invoice->id]);
        $log->actor->forceDelete();

        $page->call('mountTableAction', 'history', (string) $invoice->id)->assertDontSee('Belum ada tindakan penagihan');
    }

    // --------------------------------------------------------- architecture

    public function test_the_page_has_no_write_path_of_its_own(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformPayments.php'));

        foreach (['->update(', '->save(', '->delete(', '->forceDelete(', 'Subscription::create', 'Invoice::create', 'Invoice::update', '->create('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertStringContainsString(InvoiceSyncer::class, str_replace('\\\\', '\\', $source));
    }
}

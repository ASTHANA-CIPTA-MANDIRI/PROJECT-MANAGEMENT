<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\PlatformPayments;
use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Pulihkan pembayaran" row action on the Super Admin Pembayaran page
 * (late-payment recovery, Stage B). The engine is covered by
 * LateRecoveryTest; this is the page layer: who may use it, where it shows,
 * the reason, the throttle and the operator-facing messages.
 */
class PlatformPaymentsRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.doku.com';

    private const REASON = 'Pelanggan membayar setelah kedaluwarsa, bukti transfer ada';

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

    private function expired(array $attrs = []): Invoice
    {
        return Invoice::factory()->for(Organization::factory()->create())->create($attrs + [
            'status' => Invoice::STATUS_EXPIRED,
            'expires_at' => now()->subDays(2),
            'created_at' => now()->subDays(3),
        ]);
    }

    private function fakeStatus(Invoice $invoice, string $status): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response([
                'order' => ['invoice_number' => $invoice->invoice_number, 'amount' => $invoice->amount],
                'transaction' => ['status' => $status, 'original_request_id' => $invoice->doku_request_id],
                'payment' => ['url' => 'https://evil.example/URL-SENTINEL', 'token_id' => 'TOKEN-SENTINEL'],
                'signature' => 'SIGNATURE-SENTINEL',
            ]),
        ]);
    }

    private function recover($page, Invoice $invoice, string $reason = self::REASON)
    {
        return $page->callTableAction('recoverLatePayment', $invoice, ['reason' => $reason]);
    }

    private function lastBody(): string
    {
        $all = session('filament.notifications') ?? [];
        $last = end($all);

        return (string) ($last['body'] ?? '');
    }

    public function test_it_is_offered_only_for_expired_rows(): void
    {
        $expired = $this->expired();
        $pending = Invoice::factory()->for(Organization::factory()->create())->create();
        $paid = Invoice::factory()->paid()->for(Organization::factory()->create())->create();
        $failed = Invoice::factory()->failed()->for(Organization::factory()->create())->create();

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->assertTableActionVisible('recoverLatePayment', $expired)
            ->assertTableActionHidden('recoverLatePayment', $pending)
            ->assertTableActionHidden('recoverLatePayment', $paid)
            ->assertTableActionHidden('recoverLatePayment', $failed);

        // Sync is for pending rows only, so a row never carries both icons.
        $page->assertTableActionHidden('syncInvoice', $expired);

        $row = collect(preg_split('/<tr\b/', $page->lastRenderedDom))->first(fn ($r) => str_contains($r, $expired->invoice_number));
        $this->assertStringContainsString('aria-label="Pulihkan pembayaran"', $row);
        $this->assertStringContainsString('rk-pp__act--recover', $row);
        $this->assertStringContainsString('aria-label="Riwayat"', $row);
    }

    public function test_a_crafted_request_for_a_non_expired_invoice_changes_nothing(): void
    {
        $pending = Invoice::factory()->for(Organization::factory()->create())->create(['created_at' => now()->subHour()]);
        Http::fake();

        Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'recoverLatePayment', (string) $pending->id)
            ->assertNotified();

        Http::assertNothingSent();
        $this->assertSame(Invoice::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_every_role_but_super_admin_is_forbidden_on_the_livewire_action(): void
    {
        $invoice = $this->expired();
        Http::fake();
        $organization = Organization::factory()->create();

        foreach (['owner', 'admin', 'member', null] as $role) {
            $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

            $user = User::factory()->create();
            if ($role !== null) {
                $organization->users()->attach($user->id, ['role' => $role]);
            }
            $this->actingAs($user);

            $page->call('mountTableAction', 'recoverLatePayment', (string) $invoice->id)->assertForbidden();
            $page->call('callMountedTableAction')->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertSame(0, BillingAuditLog::count());
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
    }

    public function test_the_confirmation_warns_and_requires_a_reason(): void
    {
        $invoice = $this->expired();
        Http::fake();

        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class)
            ->call('mountTableAction', 'recoverLatePayment', (string) $invoice->id);

        $html = $page->lastRenderedDom;
        $this->assertStringContainsString('Pulihkan hanya jika DOKU mengonfirmasi SUCCESS', $html);
        $this->assertStringContainsString('Langganan dimulai dari sekarang', $html);
        $this->assertStringContainsString('tercatat di riwayat audit', $html);

        foreach (['', 'terlalu', '      x         ', str_repeat('a', 501)] as $reason) {
            $this->recover(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice, $reason)
                ->assertHasTableActionErrors(['reason']);
        }

        Http::assertNothingSent();
        $this->assertSame(0, BillingAuditLog::count());
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
    }

    public function test_a_successful_recovery_activates_audits_and_says_so(): void
    {
        $actor = $this->superAdmin();
        $invoice = $this->expired();
        $this->fakeStatus($invoice, 'SUCCESS');

        $page = $this->recover(Livewire::actingAs($actor)->test(PlatformPayments::class), $invoice)
            ->assertHasNoTableActionErrors();

        $this->assertSame('Pembayaran dipulihkan, langganan diaktifkan.', $this->lastBody());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::count());

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_LATE_RECOVERY, $log->action);
        $this->assertSame(SyncOutcome::RECOVERED, $log->result);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame(self::REASON, $log->reason);

        // Once paid, the action disappears and shows in the history modal under its own name.
        $page->assertTableActionHidden('recoverLatePayment', $invoice->fresh());
        $history = Livewire::actingAs($actor)->test(PlatformPayments::class)->call('mountTableAction', 'history', (string) $invoice->id);
        $this->assertStringContainsString('Pulihkan pembayaran', $history->lastRenderedDom);
        $this->assertStringContainsString('Pembayaran dipulihkan', $history->lastRenderedDom);
    }

    public function test_messages_per_outcome_never_leak_anything(): void
    {
        $actor = $this->superAdmin();

        $cases = [
            'not paid at DOKU' => [fn () => $this->fakeStatus($this->current, 'PENDING'), 'DOKU belum menyatakan pembayaran ini sukses, tidak ada yang diubah.'],
            'unreachable' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('SQLSTATE test-secret-key')]), 'DOKU tidak dapat dihubungi'],
        ];

        foreach ($cases as $label => [$arrange, $expected]) {
            $this->current = $this->expired();
            $arrange();

            $this->recover(Livewire::actingAs($actor)->test(PlatformPayments::class), $this->current);

            $this->assertStringContainsString($expected, $this->lastBody(), $label);
            foreach (self::SENTINELS as $needle) {
                $this->assertStringNotContainsString($needle, $this->lastBody(), $label);
            }
            $this->assertSame(Invoice::STATUS_EXPIRED, $this->current->fresh()->status, $label);
        }

        $this->assertSame(0, Subscription::count());
    }

    private Invoice $current;

    public function test_too_old_and_mismatch_messages(): void
    {
        $actor = $this->superAdmin();
        config(['subscriptions.late_recovery_max_days' => 30]);

        $old = $this->expired(['expires_at' => now()->subDays(31)]);
        $mismatch = $this->expired();
        Http::fake([self::BASE.'/orders/v1/status/'.$mismatch->invoice_number => Http::response([
            'order' => ['invoice_number' => $mismatch->invoice_number, 'amount' => 1],
            'transaction' => ['status' => 'SUCCESS', 'original_request_id' => $mismatch->doku_request_id],
        ])]);

        $this->recover(Livewire::actingAs($actor)->test(PlatformPayments::class), $old);
        $this->assertSame('Invoice sudah kedaluwarsa lebih dari 30 hari, tidak bisa dipulihkan.', $this->lastBody());
        Http::assertNothingSent();

        $this->recover(Livewire::actingAs($actor)->test(PlatformPayments::class), $mismatch);
        $this->assertStringContainsString('tidak cocok', $this->lastBody());
        $this->assertSame(Invoice::STATUS_EXPIRED, $mismatch->fresh()->status);
    }

    public function test_a_failing_audit_write_gives_a_safe_message_and_no_mutation(): void
    {
        $invoice = $this->expired();
        $this->fakeStatus($invoice, 'SUCCESS');
        BillingAuditLog::creating(fn () => throw new \RuntimeException('audit down'));

        $page = $this->recover(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $this->assertStringContainsString('Gagal menerapkan', $this->lastBody());
        $this->assertSame(Invoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(0, Subscription::count());
        foreach (self::SENTINELS as $needle) {
            $this->assertStringNotContainsString($needle, $this->lastBody());
            $this->assertStringNotContainsString($needle, $page->lastRenderedDom);
        }
    }

    public function test_it_has_its_own_30_second_throttle_independent_of_check_and_sync(): void
    {
        $invoice = $this->expired();
        $this->fakeStatus($invoice, 'PENDING');
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);

        $page->callTableAction('checkStatus', $invoice);
        Http::assertSentCount(1);

        // A status check just before does not hold the recovery back.
        $this->recover($page, $invoice);
        Http::assertSentCount(2);

        // A second recovery within 30 s is throttled: no HTTP, no audit row.
        $this->recover($page, $invoice);
        $this->assertMatchesRegularExpression('/^Terlalu sering, coba lagi dalam \d+ detik\.$/', $this->lastBody());
        Http::assertSentCount(2);
        $this->assertSame(2, BillingAuditLog::count());

        $this->travel(31)->seconds();
        $this->recover($page, $invoice);
        Http::assertSentCount(3);
    }

    public function test_it_shares_the_per_user_budget_of_20_actions_a_minute(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class);
        Http::fake(['*' => Http::response(['order' => ['invoice_number' => 'x'], 'transaction' => ['status' => 'PENDING']])]);

        for ($i = 0; $i < 20; $i++) {
            $this->recover($page, $this->expired());
        }
        Http::assertSentCount(20);

        $this->recover($page, $this->expired());
        $this->assertStringContainsString('Terlalu sering', $this->lastBody());
        Http::assertSentCount(20);
    }

    public function test_no_public_property_holds_a_record_or_a_doku_result(): void
    {
        $invoice = $this->expired();
        $this->fakeStatus($invoice, 'SUCCESS');

        $page = $this->recover(Livewire::actingAs($this->superAdmin())->test(PlatformPayments::class), $invoice);

        $state = json_encode($page->payload['serverMemo']['data'] ?? []);
        foreach (self::SENTINELS as $needle) {
            $this->assertStringNotContainsString($needle, $state);
        }
        foreach ($page->instance()->getPublicPropertiesDefinedBySubClass() as $value) {
            $this->assertNotInstanceOf(Invoice::class, $value);
        }
    }

    public function test_the_page_source_has_no_write_path_for_the_recovery(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformPayments.php'));

        foreach (['->update(', 'Subscription::create', 'Invoice::update'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('->recoverLate(', $source);
    }
}

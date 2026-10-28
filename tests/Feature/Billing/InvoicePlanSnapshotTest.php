<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Doku\DokuSignature;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An invoice freezes the plan's duration_days and name at checkout. Changing
 * or removing the plan in config between checkout and payment must neither
 * change the period granted nor leave a paid invoice without a Subscription.
 * Legacy invoices (no snapshot) keep the old config-lookup behaviour.
 */
class InvoicePlanSnapshotTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice): array
    {
        return [
            'order' => ['invoice_number' => $invoice->invoice_number, 'amount' => $invoice->amount],
            'transaction' => ['status' => 'SUCCESS', 'original_request_id' => $invoice->doku_request_id],
        ];
    }

    private function payViaWebhook(Invoice $invoice): void
    {
        $rawBody = json_encode($this->payload($invoice));
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $component = DokuSignature::componentString(
            self::CLIENT_ID, $requestId, $timestamp, self::PATH, DokuSignature::digest($rawBody)
        );

        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([
            'Client-Id' => self::CLIENT_ID,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => DokuSignature::sign($component, self::SECRET_KEY),
            'Content-Type' => 'application/json',
        ]), $rawBody)->assertOk()->assertJson(['message' => 'paid']);
    }

    private function payViaPolling(Invoice $invoice): void
    {
        Http::fake([
            self::BASE.'/orders/v1/status/'.$invoice->invoice_number => Http::response($this->payload($invoice)),
        ]);

        $this->artisan('subscriptions:sync-pending-invoices')->assertSuccessful();
    }

    private function pendingInvoice(array $attrs = [], ?Organization $organization = null): Invoice
    {
        return Invoice::factory()->for($organization ?? Organization::factory()->create())->create(array_merge([
            'created_at' => now()->subMinutes(10),
        ], $attrs));
    }

    private function subscriptionDays(Organization $organization): int
    {
        $subscription = Subscription::where('organization_id', $organization->id)->firstOrFail();

        return (int) round($subscription->starts_at->diffInDays($subscription->ends_at, false));
    }

    // ------------------------------------------------------------- checkout

    public function test_checkout_snapshots_duration_and_name_from_the_server_side_plan(): void
    {
        Http::fake(['*/checkout/v1/payment' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['token_id' => 't-1', 'url' => 'https://api-sandbox.doku.com/checkout/v1/session/x']],
        ])]);
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        // Client-sent duration/name/amount are ignored: only the plan code counts.
        $this->actingAs($user)->post('/organizations/subscribe', [
            'plan' => 'yearly',
            'duration_days' => 1,
            'plan_name' => 'Hacked',
            'amount' => 1,
        ])->assertRedirect();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(365, $invoice->duration_days);
        $this->assertSame('Yearly', $invoice->plan_name);
        $this->assertSame(1500000, $invoice->amount);
    }

    public function test_the_snapshot_stays_locked_when_the_plan_config_changes_afterwards(): void
    {
        Http::fake(['*/checkout/v1/payment' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['token_id' => 't-1', 'url' => 'https://api-sandbox.doku.com/checkout/v1/session/x']],
        ])]);
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'monthly'])->assertRedirect();

        SubscriptionPlan::where('code', 'monthly')->update(['duration_days' => 90, 'name' => 'Renamed']);

        $invoice = Invoice::firstOrFail()->fresh();
        $this->assertSame(30, $invoice->duration_days);
        $this->assertSame('Monthly', $invoice->plan_name);
    }

    // ------------------------------------------------------ activation (snapshot)

    public function test_webhook_uses_the_snapshot_duration_when_the_plan_duration_changed(): void
    {
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly', 'duration_days' => 30]);
        SubscriptionPlan::where('code', 'monthly')->update(['duration_days' => 90]);

        $this->payViaWebhook($invoice);

        $this->assertSame(30, $this->subscriptionDays($invoice->organization));
    }

    public function test_polling_uses_the_snapshot_duration_when_the_plan_duration_changed(): void
    {
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly', 'duration_days' => 30]);
        SubscriptionPlan::where('code', 'monthly')->update(['duration_days' => 90]);

        $this->payViaPolling($invoice);

        $this->assertSame(30, $this->subscriptionDays($invoice->organization));
        $this->assertNotNull($invoice->fresh()->subscription_id);
    }

    public function test_webhook_still_creates_the_subscription_when_the_plan_was_removed_after_checkout(): void
    {
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly', 'duration_days' => 30]);
        SubscriptionPlan::where('code', 'monthly')->delete(); // query-builder delete: bypasses the model guard, simulating a legacy row that is gone

        $this->payViaWebhook($invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertSame(30, $this->subscriptionDays($invoice->organization));
    }

    public function test_polling_still_creates_the_subscription_when_the_plan_was_removed_after_checkout(): void
    {
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly', 'duration_days' => 30]);
        SubscriptionPlan::query()->delete(); // see above

        $this->payViaPolling($invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertSame(30, $this->subscriptionDays($invoice->organization));
    }

    public function test_renewal_extends_from_the_active_end_date_using_the_snapshot_duration(): void
    {
        $organization = Organization::factory()->create();
        $existingEndsAt = now()->addDays(10);
        Subscription::factory()->for($organization)->create(['ends_at' => $existingEndsAt, 'cancelled_at' => null]);
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly', 'duration_days' => 30], $organization);
        SubscriptionPlan::where('code', 'monthly')->update(['duration_days' => 90]);

        $this->payViaWebhook($invoice);

        $this->assertSame(1, Subscription::where('organization_id', $organization->id)->count());
        $this->assertEqualsWithDelta(
            $existingEndsAt->copy()->addDays(30)->timestamp,
            Subscription::where('organization_id', $organization->id)->first()->ends_at->timestamp,
            2
        );
    }

    // ------------------------------------------------------ activation (legacy)

    public function test_a_legacy_invoice_without_a_snapshot_falls_back_to_the_config_lookup(): void
    {
        $invoice = $this->pendingInvoice(['plan_code' => 'yearly'])->fresh();
        $invoice->forceFill(['duration_days' => null, 'plan_name' => null])->save();
        $this->assertNull($invoice->fresh()->duration_days);

        $this->payViaWebhook($invoice);

        $this->assertSame(365, $this->subscriptionDays($invoice->organization));
    }

    public function test_a_legacy_invoice_without_a_snapshot_and_without_a_plan_is_paid_without_a_subscription_and_logged(): void
    {
        Log::spy();
        $invoice = $this->pendingInvoice(['plan_code' => 'monthly']);
        $invoice->forceFill(['duration_days' => null, 'plan_name' => null])->save();
        SubscriptionPlan::query()->delete(); // see above

        $this->payViaWebhook($invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNull($invoice->subscription_id);
        $this->assertSame(0, Subscription::count());
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message, $context) => str_contains($message, 'no duration snapshot') && $context['invoice_id'] === $invoice->id
        );
    }

    // ---------------------------------------------------------------- factory

    public function test_the_factory_snapshot_follows_the_plan_code(): void
    {
        $monthly = Invoice::factory()->create();
        $yearly = Invoice::factory()->create(['plan_code' => 'yearly', 'amount' => 1500000]);
        $legacy = Invoice::factory()->withoutPlanSnapshot()->create();

        $this->assertSame([30, 'Monthly'], [$monthly->duration_days, $monthly->plan_name]);
        $this->assertSame([365, 'Yearly'], [$yearly->duration_days, $yearly->plan_name]);
        $this->assertSame([null, null], [$legacy->duration_days, $legacy->plan_name]);
    }
}

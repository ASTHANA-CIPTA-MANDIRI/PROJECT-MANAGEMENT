<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionActivationService;
use App\Support\OrganizationContext;
use App\Support\SubscriptionPanel;
use App\Support\SubscriptionPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * The plan catalog lives in the subscription_plans table (seeded from
 * config('subscriptions.plans') by its migration). These tests pin that the
 * move changed nothing for buyers, and that an edited or deactivated plan
 * only affects checkouts made afterwards.
 */
class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Organization $organization): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    /** The billing page needs a panel user (verified, some role) and the checkout switch on. */
    private function panelOwner(Organization $organization): User
    {
        config(['subscriptions.checkout_enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([Role::firstOrCreate(['name' => 'Panel user'])]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user->fresh();
    }

    private function fakeDoku(): void
    {
        Http::fake(['*/checkout/v1/payment' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['token_id' => 't-1', 'url' => 'https://api-sandbox.doku.com/checkout/v1/session/x']],
        ])]);
    }

    // ------------------------------------------------- migration seed

    public function test_the_migration_seeds_the_table_from_the_config_so_behaviour_is_unchanged(): void
    {
        $plans = SubscriptionPlan::query()->ordered()->get();

        $this->assertSame(['monthly', 'yearly'], $plans->pluck('code')->all());
        $this->assertTrue($plans->every(fn ($plan) => $plan->is_active));

        foreach ($plans as $plan) {
            $config = config('subscriptions.plans.'.$plan->code);
            $this->assertSame($config['name'], $plan->name);
            $this->assertSame($config['amount'], $plan->amount);
            $this->assertSame($config['duration_days'], $plan->duration_days);
        }

        $this->assertSame([1, 2], $plans->pluck('sort_order')->all(), 'simple order: 1, 2, ...');
        $this->assertSame(
            ['monthly' => ['name' => 'Monthly', 'amount' => 150000, 'duration_days' => 30], 'yearly' => ['name' => 'Yearly', 'amount' => 1500000, 'duration_days' => 365]],
            SubscriptionPlans::sellable()
        );
    }

    public function test_down_drops_the_table_and_up_seeds_it_again_from_the_config(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_create_subscription_plans_table.php');

        SubscriptionPlan::where('code', 'monthly')->update(['amount' => 777000]);
        $migration->down();
        $migration->up();

        $this->assertSame(2, SubscriptionPlan::count(), 'seeded again from config after a full down/up, edits are gone with the table');
        $this->assertSame(150000, SubscriptionPlan::where('code', 'monthly')->value('amount'));
    }

    // ------------------------------------------------- reading

    public function test_find_sees_an_inactive_plan_but_nothing_that_sells_does(): void
    {
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);

        $this->assertSame(365, SubscriptionPlans::find('yearly')['duration_days'], 'activation fallback / labels must still resolve it');
        $this->assertNull(SubscriptionPlans::findSellable('yearly'));
        $this->assertSame(['monthly'], SubscriptionPlans::codes());
        $this->assertSame(['monthly'], array_keys(SubscriptionPlans::sellable()));
        $this->assertSame(['monthly' => 'Monthly', 'yearly' => 'Yearly'], SubscriptionPlans::names());
        $this->assertNull(SubscriptionPlans::find('nope'));
    }

    public function test_sellable_plans_follow_sort_order_then_id(): void
    {
        SubscriptionPlan::where('code', 'monthly')->update(['sort_order' => 50]);
        SubscriptionPlan::where('code', 'yearly')->update(['sort_order' => 5]);
        SubscriptionPlan::factory()->create(['code' => 'promo', 'sort_order' => 5]);

        $this->assertSame(['yearly', 'promo', 'monthly'], SubscriptionPlans::codes());
    }

    // ------------------------------------------------- checkout

    public function test_an_inactive_plan_is_rejected_by_checkout_and_no_invoice_or_doku_call_happens(): void
    {
        $this->fakeDoku();
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);
        $organization = Organization::factory()->create();

        $this->actingAs($this->owner($organization))
            ->from('/organization/billing')
            ->post('/organizations/subscribe', ['plan' => 'yearly'])
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_checkout_charges_the_latest_database_price_and_freezes_it_on_the_invoice(): void
    {
        $this->fakeDoku();
        SubscriptionPlan::where('code', 'monthly')->update(['amount' => 199000, 'duration_days' => 45, 'name' => 'Bulanan Plus']);
        $organization = Organization::factory()->create();

        $this->actingAs($this->owner($organization))->post('/organizations/subscribe', ['plan' => 'monthly'])->assertRedirect();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(199000, $invoice->amount);
        $this->assertSame(45, $invoice->duration_days);
        $this->assertSame('Bulanan Plus', $invoice->plan_name);
    }

    public function test_changing_the_plan_afterwards_does_not_touch_an_existing_invoice_and_payment_uses_the_snapshot(): void
    {
        $this->fakeDoku();
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $this->actingAs($owner)->post('/organizations/subscribe', ['plan' => 'monthly'])->assertRedirect();
        $invoice = Invoice::firstOrFail();

        SubscriptionPlan::where('code', 'monthly')->update(['amount' => 999000, 'duration_days' => 90, 'name' => 'Renamed', 'is_active' => false]);

        $invoice->refresh();
        $this->assertSame([150000, 30, 'Monthly'], [$invoice->amount, $invoice->duration_days, $invoice->plan_name]);

        $this->postWebhook($invoice);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $subscription = Subscription::where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(30, (int) round($subscription->starts_at->floatDiffInDays($subscription->ends_at)));
    }

    // ------------------------------------------------- panel / billing page

    public function test_an_inactive_plan_is_not_offered_on_the_panel_or_the_billing_page(): void
    {
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);
        $organization = Organization::factory()->create();
        $owner = $this->panelOwner($organization);

        $this->assertSame(['monthly'], array_keys(SubscriptionPanel::for($organization, $owner)['plans']));

        $html = $this->actingAs($owner)->get('/organization/billing')->assertOk()->getContent();
        $this->assertStringContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString('Rp1.500.000', $html);
    }

    public function test_the_billing_page_shows_an_edited_price(): void
    {
        SubscriptionPlan::where('code', 'monthly')->update(['amount' => 175000]);
        $organization = Organization::factory()->create();

        $this->actingAs($this->panelOwner($organization))->get('/organization/billing')->assertOk()->assertSee('Rp175.000');
    }

    // ------------------------------------------------- model rules

    public function test_a_plan_cannot_be_deleted_through_the_model(): void
    {
        $plan = SubscriptionPlan::where('code', 'monthly')->firstOrFail();

        $this->expectException(LogicException::class);

        try {
            $plan->delete();
        } finally {
            $this->assertSame(1, SubscriptionPlan::where('code', 'monthly')->count());
        }
    }

    public function test_a_plan_code_cannot_be_changed(): void
    {
        $plan = SubscriptionPlan::where('code', 'monthly')->firstOrFail();

        try {
            $plan->update(['code' => 'renamed']);
            $this->fail('Changing the code must throw.');
        } catch (LogicException) {
            $this->assertSame('monthly', $plan->fresh()->code);
        }
    }

    public function test_the_schema_enforces_a_unique_code(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SubscriptionPlan::factory()->create(['code' => 'monthly']);
    }

    // ------------------------------------------------- helpers

    private function postWebhook(Invoice $invoice): void
    {
        // The signed-webhook path is covered by DokuWebhookTest; what matters here is the
        // single entry point both the webhook and polling feed.
        $result = app(SubscriptionActivationService::class)->process([
            'order' => ['invoice_number' => $invoice->invoice_number, 'amount' => $invoice->amount],
            'transaction' => ['status' => 'SUCCESS', 'original_request_id' => $invoice->doku_request_id],
        ]);

        $this->assertSame('paid', $result);
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\PlatformPayments;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * Super Admin "Pembayaran": a READ-ONLY, cross-organization invoice overview.
 * Authorization is isSuperAdmin() (like PlatformOrganizations), not the
 * Organization role: an Owner of an organization is refused as well.
 */
class PlatformPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    private function invoice(array $attributes = [], ?Organization $organization = null): Invoice
    {
        return Invoice::factory()->create($attributes + [
            'organization_id' => $organization?->id ?? Organization::factory(),
        ]);
    }

    // ----------------------------------------------------------- access

    public function test_super_admin_opens_the_page_and_sees_invoices_of_two_organizations(): void
    {
        $this->actingAs($this->superAdmin());

        $this->invoice(['invoice_number' => 'INV-AAA'], Organization::factory()->create(['name' => 'Acme Org']));
        $this->invoice(['invoice_number' => 'INV-BBB'], Organization::factory()->create(['name' => 'Globex Org']));

        // The auth middleware group enforces 2FA for Super Admins (covered below).
        config(['system.security.require_2fa_for_super_admin' => false]);

        $this->get(PlatformPayments::getUrl())->assertOk();

        Livewire::test(PlatformPayments::class)
            ->assertSuccessful()
            ->assertSee('INV-AAA')->assertSee('Acme Org')
            ->assertSee('INV-BBB')->assertSee('Globex Org');
    }

    /** The 2FA requirement for Super Admins is inherited from the auth middleware group. */
    public function test_super_admin_without_confirmed_two_factor_is_redirected_when_2fa_is_required(): void
    {
        config(['system.security.require_2fa_for_super_admin' => true]);

        $this->actingAs($this->superAdmin())
            ->get(PlatformPayments::getUrl())
            ->assertRedirect(route('filament.pages.my-profile'));
    }

    public function test_every_organization_role_and_plain_users_are_forbidden(): void
    {
        $organization = Organization::factory()->create();

        foreach (['owner', 'admin', 'member', null] as $role) {
            $user = User::factory()->create();

            if ($role !== null) {
                $organization->users()->attach($user->id, ['role' => $role]);
            }

            $this->actingAs($user)->get(PlatformPayments::getUrl())->assertForbidden();
            Livewire::test(PlatformPayments::class)->assertForbidden();
        }
    }

    public function test_navigation_is_registered_only_for_super_admin(): void
    {
        $method = (new ReflectionClass(PlatformPayments::class))->getMethod('shouldRegisterNavigation');
        $method->setAccessible(true);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);
        $this->assertFalse($method->invoke(null));

        $this->actingAs($this->superAdmin());
        $this->assertTrue($method->invoke(null));
    }

    /**
     * boot() runs on every Livewire request: a component mounted by a Super
     * Admin cannot be driven further once the actor is no longer one.
     */
    public function test_authorization_is_rechecked_on_each_livewire_request(): void
    {
        $this->actingAs($this->superAdmin());
        $component = Livewire::test(PlatformPayments::class)->assertSuccessful();

        $this->actingAs(User::factory()->create());

        $component->set('tableSearchQuery', 'x')->assertForbidden();
    }

    // ------------------------------------------------------------ cards

    public function test_summary_cards_count_from_the_fixture(): void
    {
        $this->actingAs($this->superAdmin());

        // pending: 2 on time + 1 past due = 3 pending, 1 past due
        $this->invoice();
        $this->invoice();
        $this->invoice(['expires_at' => now()->subHour()]);

        // paid: two inside 30 days (with subscription), one outside, one without subscription
        $subscription = Subscription::factory()->create();
        $this->invoice(['status' => 'paid', 'amount' => 150000, 'paid_at' => now()->subDays(2), 'subscription_id' => $subscription->id]);
        $this->invoice(['status' => 'paid', 'amount' => 1500000, 'paid_at' => now()->subDays(29), 'subscription_id' => $subscription->id]);
        $this->invoice(['status' => 'paid', 'amount' => 999, 'paid_at' => now()->subDays(40), 'subscription_id' => $subscription->id]);
        $this->invoice(['status' => 'paid', 'amount' => 150000, 'paid_at' => now()->subDay(), 'subscription_id' => null]);

        // expired: 2 within 7 days, 1 older
        $this->invoice(['status' => 'expired', 'expires_at' => now()->subDays(1)]);
        $this->invoice(['status' => 'expired', 'expires_at' => now()->subDays(6)]);
        $this->invoice(['status' => 'expired', 'expires_at' => now()->subDays(20)]);

        // Order: pending, pending past due, paid 30d, expired 7d, paid without subscription.
        $stats = Livewire::test(PlatformPayments::class)->instance()->paymentStats();

        $this->assertCount(5, $stats);
        $this->assertSame('3', $stats[0]['value']);
        $this->assertSame('1', $stats[1]['value']);
        $this->assertSame('3', $stats[2]['value']);
        $this->assertSame('Rp 1.800.000', $stats[2]['note']);
        $this->assertSame('2', $stats[3]['value']);
        $this->assertSame('1', $stats[4]['value']);
    }

    // ---------------------------------------------------- search/filter

    public function test_search_by_invoice_number_and_organization_name(): void
    {
        $this->actingAs($this->superAdmin());

        $this->invoice(['invoice_number' => 'INV-ALPHA'], Organization::factory()->create(['name' => 'Acme Org']));
        $this->invoice(['invoice_number' => 'INV-BRAVO'], Organization::factory()->create(['name' => 'Globex Org']));

        Livewire::test(PlatformPayments::class)
            ->set('tableSearchQuery', 'ALPHA')
            ->assertSee('INV-ALPHA')->assertDontSee('INV-BRAVO')
            ->set('tableSearchQuery', 'Globex')
            ->assertSee('INV-BRAVO')->assertDontSee('INV-ALPHA');
    }

    public function test_status_plan_organization_and_date_filters(): void
    {
        $this->actingAs($this->superAdmin());

        $acme = Organization::factory()->create(['name' => 'Acme Org']);
        $globex = Organization::factory()->create(['name' => 'Globex Org']);

        $this->invoice(['invoice_number' => 'INV-PEND'], $acme);
        $this->invoice(['invoice_number' => 'INV-PAID', 'status' => 'paid', 'paid_at' => now(), 'plan_code' => 'yearly'], $globex);
        $this->invoice(['invoice_number' => 'INV-OLD', 'status' => 'expired', 'created_at' => now()->subDays(40)], $globex);

        $page = Livewire::test(PlatformPayments::class);

        $page->filterTable('status', 'paid')
            ->assertSee('INV-PAID')->assertDontSee('INV-PEND')->assertDontSee('INV-OLD');

        $page->removeTableFilter('status')->filterTable('plan_code', 'yearly')
            ->assertSee('INV-PAID')->assertDontSee('INV-PEND');

        $page->removeTableFilter('plan_code')->filterTable('organization_id', $acme->id)
            ->assertSee('INV-PEND')->assertDontSee('INV-PAID');

        $page->removeTableFilter('organization_id')->filterTable('created_between', [
            'created_from' => now()->subDays(10)->toDateString(),
            'created_until' => now()->addDay()->toDateString(),
        ])->assertSee('INV-PEND')->assertSee('INV-PAID')->assertDontSee('INV-OLD');
    }

    public function test_needs_attention_filter_shows_only_past_due_and_paid_without_subscription(): void
    {
        $this->actingAs($this->superAdmin());

        $subscription = Subscription::factory()->create();

        $this->invoice(['invoice_number' => 'INV-FINE-PENDING']);
        $this->invoice(['invoice_number' => 'INV-LATE', 'expires_at' => now()->subHour()]);
        $this->invoice(['invoice_number' => 'INV-ORPHAN', 'status' => 'paid', 'paid_at' => now(), 'subscription_id' => null]);
        $this->invoice(['invoice_number' => 'INV-FINE-PAID', 'status' => 'paid', 'paid_at' => now(), 'subscription_id' => $subscription->id]);
        $this->invoice(['invoice_number' => 'INV-EXPIRED', 'status' => 'expired']);

        Livewire::test(PlatformPayments::class)
            ->filterTable('needs_attention', true)
            ->assertSee('INV-LATE')->assertSee('INV-ORPHAN')
            ->assertDontSee('INV-FINE-PENDING')->assertDontSee('INV-FINE-PAID')->assertDontSee('INV-EXPIRED');
    }

    public function test_default_order_is_newest_first(): void
    {
        $this->actingAs($this->superAdmin());

        $this->invoice(['invoice_number' => 'INV-FIRST']);
        $this->invoice(['invoice_number' => 'INV-SECOND']);

        Livewire::test(PlatformPayments::class)->assertSeeInOrder(['INV-SECOND', 'INV-FIRST']);
    }

    // ------------------------------------------------- sensitive data

    public function test_no_doku_secrets_in_html_or_livewire_state(): void
    {
        $this->actingAs($this->superAdmin());

        $this->invoice([
            'doku_payment_url' => 'https://pay.example/SECRET-PAYMENT-URL',
            'doku_token_id' => 'SECRET-TOKEN-ID',
            'doku_request_id' => 'SECRET-REQUEST-ID',
            'doku_reference' => 'SECRET-REFERENCE',
        ]);

        $component = Livewire::test(PlatformPayments::class);
        $serialized = $component->payload['effects']['html'].json_encode($component->payload).json_encode($component->effects);

        foreach (['SECRET-PAYMENT-URL', 'SECRET-TOKEN-ID', 'SECRET-REQUEST-ID', 'SECRET-REFERENCE', 'doku_payment_url', 'doku_token_id', 'doku_request_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $serialized);
        }

        config(['system.security.require_2fa_for_super_admin' => false]);
        $this->get(PlatformPayments::getUrl())->assertOk()->assertDontSee('SECRET-', false);
    }

    // ------------------------------------------- browsing is side-effect free

    public function test_no_outgoing_http_and_no_write_after_interactions(): void
    {
        Http::fake();
        $this->actingAs($this->superAdmin());

        $this->invoice(['expires_at' => now()->subHour()]);
        $before = DB::table('invoices')->get()->toArray();
        $subscriptions = Subscription::count();

        $page = Livewire::test(PlatformPayments::class)
            ->set('tableSearchQuery', 'INV')
            ->filterTable('needs_attention', true)
            ->sortTable('amount');

        // The row actions (check status / sync / recover late payment / history, covered in
        // PlatformPaymentsActionsTest) are the ONLY actions; merely browsing
        // never reaches DOKU nor writes.
        $this->assertSame(
            ['checkStatus', 'syncInvoice', 'recoverLatePayment', 'history'],
            array_keys($page->instance()->getCachedTableActions())
        );
        $this->assertSame([], array_merge(
            array_keys($page->instance()->getCachedTableBulkActions()),
            array_keys($page->instance()->getCachedTableEmptyStateActions()),
        ));
        $this->assertEquals($before, DB::table('invoices')->get()->toArray());
        $this->assertSame($subscriptions, Subscription::count());
        Http::assertNothingSent();
    }

    // ----------------------------------------------------------- queries

    public function test_query_count_does_not_grow_with_the_number_of_invoices(): void
    {
        $this->actingAs($this->superAdmin());

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(PlatformPayments::class);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->invoice();
        $count(); // warm caches (roles, settings)
        $one = $count();

        for ($i = 0; $i < 19; $i++) {
            $this->invoice();
        }

        $this->assertSame($one, $count());
    }
}

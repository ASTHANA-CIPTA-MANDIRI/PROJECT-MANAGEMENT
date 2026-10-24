<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /organization/billing: a pricing card with a Monthly/Yearly switch for
 * Owner/Admin, status plus a "ask an owner or admin" message for a plain
 * Member. Rendered over real HTTP (the page is also where the paywall
 * redirect lands). The checkout itself is covered by SubscriptionCheckoutTest.
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/organization/billing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['subscriptions.checkout_enabled' => true]);
    }

    /** A verified user with some Spatie role: the panel itself requires one (User::canAccessFilament()). */
    private function panelUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([Role::firstOrCreate(['name' => 'Panel user'])]);

        return $user->fresh();
    }

    private function actor(Organization $organization, string $role): User
    {
        $user = $this->panelUser();
        $organization->users()->attach($user->id, ['role' => $role]);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    public function test_owner_and_admin_see_the_card_both_prices_the_switch_and_the_button(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);

        foreach (['owner', 'admin'] as $role) {
            $html = $this->actingAs($this->actor($organization, $role))->get(self::URL)->assertOk()->getContent();

            $this->assertStringContainsString('Rp150.000', $html, $role);
            $this->assertStringContainsString('Rp1.500.000', $html, $role);
            $this->assertStringContainsString('/bulan', $html, $role);
            $this->assertStringContainsString('/tahun', $html, $role);
            $this->assertStringContainsString(__('Monthly'), $html, $role);
            $this->assertStringContainsString(__('Yearly'), $html, $role);
            $this->assertStringContainsString('x-data="{ period:', $html, $role);
            $this->assertStringContainsString('action="'.route('organizations.subscribe').'"', $html, $role);
            $this->assertStringContainsString('name="_token"', $html, $role);
            $this->assertStringContainsString('name="plan" value="monthly"', $html, $role);
            $this->assertStringContainsString('x-bind:value="period"', $html, $role);
            $this->assertStringContainsString(__('Subscribe now'), $html, $role);
            $this->assertStringNotContainsString(__('Renew').'</button>', $html, $role);
        }
    }

    public function test_the_yearly_saving_is_computed_from_config_and_hidden_when_not_cheaper(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');

        // 150000/30 = 5000 per day vs 1500000/365 = 4109.59 per day -> 18%.
        $this->actingAs($owner)->get(self::URL)->assertOk()->assertSee(__('Save :percent%', ['percent' => 18]));

        config(['subscriptions.plans.yearly.amount' => 1900000]);
        $this->actingAs($owner)->get(self::URL)->assertOk()->assertDontSee('Hemat');
    }

    public function test_a_plain_member_sees_status_and_the_message_but_no_card_price_or_button(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

        $html = $this->actingAs($this->actor($organization, 'member'))->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString(__('Ask an organization owner or admin to subscribe or renew.'), $html);
        $this->assertStringContainsString(__('Trial period'), $html);
        $this->assertStringNotContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString('Rp1.500.000', $html);
        $this->assertStringNotContainsString('name="plan"', $html);
        $this->assertStringNotContainsString(route('organizations.subscribe'), $html);
        $this->assertStringNotContainsString($invoice->doku_payment_url, $html);
    }

    public function test_label_is_renew_with_an_active_subscription_and_absent_when_it_has_no_end_date(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');
        $subscription = Subscription::factory()->active()->create(['organization_id' => $organization->id]);

        $html = $this->actingAs($owner)->get(self::URL)->assertOk()->getContent();
        $this->assertStringContainsString(__('Renew').'</button>', $html);
        $this->assertStringNotContainsString(__('Subscribe now').'</button>', $html);
        $this->assertStringContainsString(
            __('Renews from :date', ['date' => $subscription->ends_at->timezone(config('app.display_timezone'))->format('d M Y')]),
            $html
        );

        $subscription->update(['ends_at' => null]);

        $html = $this->actingAs($owner)->get(self::URL)->assertOk()->getContent();
        $this->assertStringNotContainsString(__('Renew').'</button>', $html);
        $this->assertStringNotContainsString('name="plan"', $html);
        $this->assertStringContainsString(__('Your subscription has no end date, so there is nothing to renew.'), $html);
    }

    public function test_pending_link_and_processing_note_only_for_a_valid_invoice_of_the_own_organization(): void
    {
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $mineInvoice = Invoice::factory()->create(['organization_id' => $mine->id]);
        $otherInvoice = Invoice::factory()->create(['organization_id' => $other->id]);
        $owner = $this->actor($mine, 'owner');

        $html = $this->actingAs($owner)->get(self::URL)->assertOk()->getContent();
        $this->assertStringContainsString($mineInvoice->doku_payment_url, $html);
        $this->assertStringContainsString(__('Continue payment'), $html);
        $this->assertStringContainsString(__('Payment is being processed, usually 5 minutes at most.'), $html);
        $this->assertStringNotContainsString($otherInvoice->doku_payment_url, $html);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote($mineInvoice->doku_payment_url, '/').'"[^>]*class="rk-bl__btn rk-bl__btn--ghost">\s*'.preg_quote(__('Continue payment'), '/').'\s*<\/a>/', $html);
        $this->assertStringNotContainsString($mineInvoice->doku_token_id, $html);
        $this->assertStringNotContainsString($mineInvoice->doku_request_id, $html);

        // Expired by time, already paid, or without a payment url: not offered.
        $mineInvoice->update(['expires_at' => now()->subMinute()]);
        $this->assertStringNotContainsString($mineInvoice->doku_payment_url, $this->actingAs($owner)->get(self::URL)->getContent());

        $mineInvoice->update(['expires_at' => now()->addHour(), 'status' => Invoice::STATUS_PAID]);
        $html = $this->actingAs($owner)->get(self::URL)->getContent();
        $this->assertStringNotContainsString($mineInvoice->doku_payment_url, $html);
        $this->assertStringNotContainsString(__('Payment is being processed, usually 5 minutes at most.'), $html);
    }

    public function test_gateway_internals_never_reach_the_html(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

        $html = $this->actingAs($this->actor($organization, 'owner'))->get(self::URL)->assertOk()->getContent();

        $this->assertStringNotContainsString($invoice->doku_token_id, $html);
        $this->assertStringNotContainsString($invoice->doku_request_id, $html);
        $this->assertStringNotContainsString($invoice->invoice_number, $html);
    }

    public function test_with_checkout_disabled_an_owner_sees_the_not_available_message_and_no_card(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        $organization = Organization::factory()->create();

        $html = $this->actingAs($this->actor($organization, 'owner'))->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString(__('Payment is not available yet'), $html);
        $this->assertStringNotContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString('name="plan"', $html);
    }

    public function test_the_page_is_open_for_a_locked_organization_and_for_a_user_without_one_it_is_a_404(): void
    {
        $locked = Organization::factory()->trialExpired()->create();
        $html = $this->actingAs($this->actor($locked, 'owner'))->get(self::URL)->assertOk()->getContent();
        $this->assertStringContainsString(__('Your trial has ended. Subscribe to keep using this organization.'), $html);

        $this->actingAs($this->panelUser())->get(self::URL)->assertNotFound();
    }

    public function test_the_page_has_no_visible_header_or_breadcrumbs_but_keeps_its_tab_title_and_one_h1(): void
    {
        $organization = Organization::factory()->create();
        $html = $this->actingAs($this->actor($organization, 'owner'))->get(self::URL)->assertOk()->getContent();

        $this->assertStringNotContainsString('filament-header-heading', $html);
        $this->assertStringNotContainsString('filament-header', $html);
        $this->assertDoesNotMatchRegularExpression('/filament-breadcrumbs[^>]*>\s*<ul[^>]*>\s*<li/', $html);
        $this->assertMatchesRegularExpression('/<title>\s*'.preg_quote(__('Subscription'), '/').'\s*-/', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('/<h1 class="rk-bl__sr">'.preg_quote(__('Subscription'), '/').'<\/h1>/', $html);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(self::URL)->assertRedirect();
    }

    public function test_the_page_is_not_in_the_navigation_but_the_settings_panel_links_to_it(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');

        $this->actingAs($owner);
        $nav = collect(\Filament\Facades\Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getUrl());
        $this->assertNotEmpty($nav);
        $this->assertFalse($nav->contains(fn ($url) => str_contains((string) $url, 'organization/billing')));

        $this->get('/organization')->assertOk()->assertSee(route('filament.pages.organization/billing'), false);

        config(['subscriptions.checkout_enabled' => false]);
        $this->get('/organization')->assertOk()->assertDontSee(route('filament.pages.organization/billing'), false);
    }
}

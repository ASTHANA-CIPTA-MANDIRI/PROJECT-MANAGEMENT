<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\OrganizationSettings;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Subscription" panel on /organization: status for every member, plans,
 * buy buttons and invoice history for Owner/Admin only. Checkout itself
 * (POST /organizations/subscribe) is covered by SubscriptionCheckoutTest;
 * here the panel only has to point a plain CSRF form at it.
 */
class SubscriptionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['subscriptions.checkout_enabled' => true]);
    }

    private function actor(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    private function html(User $user): string
    {
        $this->actingAs($user);

        return Livewire::test(OrganizationSettings::class)->payload['effects']['html'];
    }

    /** The action button of a plan form, regardless of surrounding whitespace. */
    private function hasButton(string $html, string $label): bool
    {
        return (bool) preg_match('/<button(?:(?!<\/button>).)*?<span class="">\s*'.preg_quote($label, '/').'\s*<\/span>/s', $html);
    }

    public function test_owner_and_admin_see_plans_buttons_and_pending_link_but_a_plain_member_only_sees_status(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

        foreach (['owner', 'admin'] as $role) {
            $html = $this->html($this->actor($organization, $role));

            $this->assertStringContainsString(__('Subscription'), $html, $role);
            $this->assertTrue($this->hasButton($html, __('Subscribe now')), $role);
            $this->assertStringContainsString('Rp150.000', $html, $role);
            $this->assertStringContainsString('Rp1.500.000', $html, $role);
            $this->assertStringContainsString($invoice->doku_payment_url, $html, $role);
            $this->assertStringNotContainsString($invoice->invoice_number, $html, $role);
        }

        $html = $this->html($this->actor($organization, 'member'));

        $this->assertStringContainsString(__('Paid subscription'), $html);
        $this->assertStringContainsString(__('Trial period'), $html);
        $this->assertStringNotContainsString('name="plan"', $html);
        $this->assertStringNotContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString($invoice->doku_payment_url, $html);
        $this->assertStringNotContainsString(__('You have an unfinished payment'), $html);
        $this->assertStringNotContainsString(__('Payment is not available yet'), $html);
    }

    public function test_a_pending_link_of_another_organization_never_appears(): void
    {
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $otherInvoice = Invoice::factory()->create(['organization_id' => $other->id]);
        $mineInvoice = Invoice::factory()->create(['organization_id' => $mine->id]);

        $ownerOfMine = $this->actor($mine, 'owner');
        $ownerOfOther = $this->actor($other, 'owner');

        $html = $this->html($ownerOfMine);
        $this->assertStringContainsString($mineInvoice->doku_payment_url, $html);
        $this->assertStringNotContainsString($otherInvoice->doku_payment_url, $html);

        $html = $this->html($ownerOfOther);
        $this->assertStringContainsString($otherInvoice->doku_payment_url, $html);
        $this->assertStringNotContainsString($mineInvoice->doku_payment_url, $html);

        // An organization with no unfinished invoice of its own sees nothing,
        // even though another one has a valid pending invoice.
        $third = Organization::factory()->create();
        $this->assertStringNotContainsString(__('You have an unfinished payment'), $this->html($this->actor($third, 'owner')));
    }

    public function test_trial_and_paid_status_are_separate_facts(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        Subscription::factory()->active()->create(['organization_id' => $organization->id]);
        $owner = $this->actor($organization, 'owner');

        $this->actingAs($owner);
        $panel = Livewire::test(OrganizationSettings::class)->instance()->subscriptionPanel();

        $this->assertTrue($panel['paid']);
        $this->assertSame('yellow', $panel['trial']['tone']);
        $this->assertNotNull($panel['paidUntil']);
        $this->assertNotNull($panel['trialEndsAt']);
    }

    public function test_an_organization_with_an_ended_trial_can_still_open_the_panel_and_pay(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertStringContainsString(__('Your trial has ended. Subscribe to keep using this organization.'), $html);
        $this->assertTrue($this->hasButton($html, __('Subscribe now')));

        $memberHtml = $this->html($this->actor($organization, 'member'));
        $this->assertStringContainsString(__('Your trial has ended. Ask an organization owner or admin to subscribe.'), $memberHtml);
        $this->assertStringNotContainsString('name="plan"', $memberHtml);
    }

    public function test_a_grandfathered_organization_is_not_reported_as_subscribed(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => null]);
        $owner = $this->actor($organization, 'owner');

        $this->actingAs($owner);
        $panel = Livewire::test(OrganizationSettings::class)->instance()->subscriptionPanel();

        $this->assertFalse($panel['paid']);
        $this->assertFalse($panel['locked']);
        $this->assertSame('green', $panel['trial']['tone']);

        $html = $this->html($owner);
        $this->assertStringContainsString(__('Not subscribed'), $html);
        $this->assertTrue($this->hasButton($html, __('Subscribe now')));
        $this->assertFalse($this->hasButton($html, __('Renew')));
    }

    public function test_button_label_follows_subscription_state(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $owner = $this->actor($organization, 'owner');

        $html = $this->html($owner);
        $this->assertTrue($this->hasButton($html, __('Subscribe now')));
        $this->assertFalse($this->hasButton($html, __('Renew')));

        $subscription = Subscription::factory()->active()->create(['organization_id' => $organization->id]);

        $html = $this->html($owner);
        $this->assertTrue($this->hasButton($html, __('Renew')));
        $this->assertFalse($this->hasButton($html, __('Subscribe now')));
        $this->assertStringContainsString(
            __('Renews from :date', ['date' => $subscription->ends_at->timezone(config('app.display_timezone'))->format('d M Y')]),
            $html
        );
    }

    public function test_a_subscription_without_an_end_date_shows_no_renew_button(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->create(['organization_id' => $organization->id, 'ends_at' => null]);

        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertFalse($this->hasButton($html, __('Renew')));
        $this->assertFalse($this->hasButton($html, __('Subscribe now')));
        $this->assertStringNotContainsString('name="plan"', $html);
        $this->assertStringContainsString(__('Your subscription has no end date, so there is nothing to renew.'), $html);
    }

    public function test_with_checkout_disabled_status_stays_but_plans_prices_and_buttons_go(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);

        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertStringContainsString(__('Paid subscription'), $html);
        $this->assertStringContainsString(__('Trial period'), $html);
        $this->assertStringContainsString(__('Payment is not available yet'), $html);
        $this->assertStringNotContainsString('name="plan"', $html);
        $this->assertStringNotContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString('Rp1.500.000', $html);
        $this->assertFalse($this->hasButton($html, __('Subscribe now')));
    }

    public function test_checkout_is_off_by_default_in_production_unless_enabled_explicitly(): void
    {
        $config = file_get_contents(config_path('subscriptions.php'));

        $this->assertStringContainsString("env('SUBSCRIPTIONS_CHECKOUT_ENABLED', env('APP_ENV') !== 'production')", $config);
    }

    public function test_each_plan_is_a_plain_csrf_form_posting_to_the_checkout_route(): void
    {
        $organization = Organization::factory()->create();
        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertStringContainsString('action="'.route('organizations.subscribe').'"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="plan" value="monthly"', $html);
        $this->assertStringContainsString('name="plan" value="yearly"', $html);
    }

    public function test_a_failed_checkout_shows_a_safe_error_under_the_plans_and_no_exception_detail(): void
    {
        Http::fake([
            '*/checkout/v1/payment' => Http::response(['error_messages' => ['SECRET-DOKU-DETAIL']], 400),
        ]);
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');
        // The real page route sits behind Filament's panel gate (any Spatie role).
        $owner->assignRole(\App\Models\Role::create(['name' => 'Panel User']));
        $page = route('filament.pages.organization');

        $response = $this->actingAs($owner)->from($page)->followingRedirects()->post(route('organizations.subscribe'), ['plan' => 'monthly']);

        $response->assertOk();
        $response->assertSee(__('Failed to reach DOKU, please try again.'));
        $response->assertSee('rk-pf__error', false);
        $response->assertDontSee('SECRET-DOKU-DETAIL');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_valid_pending_invoice_offers_one_continue_link_and_never_exposes_doku_internals(): void
    {
        $organization = Organization::factory()->create();
        Invoice::factory()->create(['organization_id' => $organization->id, 'doku_payment_url' => 'https://example.test/older-link']);
        $newest = Invoice::factory()->create(['organization_id' => $organization->id, 'doku_payment_url' => 'https://example.test/newest-link']);

        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertStringContainsString(__('You have an unfinished payment'), $html);
        $this->assertStringContainsString(__('Continue payment'), $html);
        $this->assertSame(1, substr_count($html, 'href="https://example.test/newest-link"'));
        $this->assertStringNotContainsString('older-link', $html);
        $this->assertStringContainsString('Rp150.000', $html);
        $this->assertStringNotContainsString($newest->doku_request_id, $html);
        $this->assertStringNotContainsString($newest->doku_token_id, $html);
    }

    public function test_no_link_for_invoices_that_cannot_be_continued(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');

        $cases = [
            'paid' => Invoice::factory()->paid()->create(['organization_id' => $organization->id]),
            'failed' => Invoice::factory()->failed()->create(['organization_id' => $organization->id]),
            'expired status' => Invoice::factory()->expired()->create(['organization_id' => $organization->id]),
            'past due' => Invoice::factory()->create(['organization_id' => $organization->id, 'expires_at' => now()->subMinute()]),
            'null url' => Invoice::factory()->create(['organization_id' => $organization->id, 'doku_payment_url' => null]),
            'empty url' => Invoice::factory()->create(['organization_id' => $organization->id, 'doku_payment_url' => '']),
        ];

        $html = $this->html($owner);

        $this->assertStringNotContainsString(__('Continue payment'), $html);
        $this->assertStringNotContainsString(__('You have an unfinished payment'), $html);
        foreach ($cases as $name => $invoice) {
            if ($invoice->doku_payment_url) {
                $this->assertStringNotContainsString($invoice->doku_payment_url, $html, $name);
            }
            $this->assertStringNotContainsString($invoice->doku_token_id, $html, $name);
        }
    }

    public function test_a_pending_invoice_without_an_expiry_still_offers_the_link(): void
    {
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->create(['organization_id' => $organization->id, 'expires_at' => null]);

        $this->assertStringContainsString($invoice->doku_payment_url, $this->html($this->actor($organization, 'owner')));
    }

    public function test_the_continue_link_is_hidden_when_checkout_is_disabled(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        $organization = Organization::factory()->create();
        $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

        $html = $this->html($this->actor($organization, 'owner'));

        $this->assertStringNotContainsString($invoice->doku_payment_url, $html);
        $this->assertStringNotContainsString(__('Continue payment'), $html);
    }

    public function test_the_panel_adds_a_constant_number_of_queries_regardless_of_invoice_count(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->actor($organization, 'owner');
        $this->actingAs($owner);

        $count = function (): int {
            $page = Livewire::test(OrganizationSettings::class)->instance();
            $page->summary(); // primes the memoized organization, as the view does

            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->subscriptionPanel();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        Invoice::factory()->create(['organization_id' => $organization->id]);
        $few = $count();

        Invoice::factory()->count(12)->create(['organization_id' => $organization->id]);
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(4, $many);
    }
}

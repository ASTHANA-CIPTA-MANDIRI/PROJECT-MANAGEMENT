<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Doku\DokuPaymentException;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 8 - POST /organizations/subscribe
 * (App\Http\Controllers\SubscriptionCheckoutController). Fakes DOKU's HTTP
 * response rather than calling the real sandbox - the DokuClient/signature
 * pairing itself is covered separately
 * (tests/Unit/Support/Doku/DokuSignatureTest.php,
 * tests/Feature/Billing/DokuWebhookTest.php).
 */
class SubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Organization $organization): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'member']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    /**
     * Shape verified against a real DOKU sandbox create-payment call: the
     * whole payload sits under a top-level "response" key (alongside
     * "message": ["SUCCESS"]), not flat at the root - a flat fake here
     * would pass against the old, buggy App\Services\Doku\DokuClient
     * parsing but not against the real API.
     */
    private function fakeSuccessfulDoku(): void
    {
        Http::fake([
            '*/checkout/v1/payment' => Http::response([
                'message' => ['SUCCESS'],
                'response' => [
                    'order' => [
                        'amount' => '150000',
                        'invoice_number' => 'whatever',
                        'currency' => 'IDR',
                        'session_id' => 'abc123',
                    ],
                    'payment' => [
                        'payment_due_date' => 60,
                        'token_id' => 'token-abc123',
                        'url' => 'https://api-sandbox.doku.com/checkout/v1/session/abc123',
                        'expired_date' => '20261015234218',
                        'expired_datetime' => '2026-10-15T16:42:18Z',
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_an_owner_can_start_a_checkout_for_their_own_organization(): void
    {
        $this->fakeSuccessfulDoku();
        $organization = Organization::factory()->create();
        $user = $this->owner($organization);

        $response = $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'monthly']);

        $response->assertRedirect('https://api-sandbox.doku.com/checkout/v1/session/abc123');
        $this->assertDatabaseHas('invoices', [
            'organization_id' => $organization->id,
            'plan_code' => 'monthly',
            'amount' => 150000,
            'status' => Invoice::STATUS_PENDING,
            'doku_token_id' => 'token-abc123',
        ]);
    }

    public function test_an_admin_can_also_start_a_checkout(): void
    {
        $this->fakeSuccessfulDoku();
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'admin']);
        OrganizationContext::switch($user, $organization->id);

        $response = $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'monthly']);

        $response->assertRedirect();
        $this->assertDatabaseCount('invoices', 1);
    }

    /**
     * OrganizationPolicy::update() (Owner/Admin only) is the authorization
     * gate this controller re-uses - a plain Member is exactly the case it
     * is supposed to keep out of Organization-management actions.
     */
    public function test_a_plain_member_cannot_start_a_checkout(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->member($organization);

        $response = $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'monthly']);

        $response->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_guest_cannot_start_a_checkout(): void
    {
        $response = $this->post('/organizations/subscribe', ['plan' => 'monthly']);

        $response->assertRedirect('/login-redirect');
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Security: the checkout amount is never taken from client input, only
     * ever resolved server-side from config('subscriptions.plans') by the
     * plan *code* - there is no numeric "amount" field this request even
     * accepts, so this proves an attempt to inject one has no effect at all.
     */
    public function test_a_client_supplied_amount_is_ignored_the_server_plan_price_is_used(): void
    {
        $this->fakeSuccessfulDoku();
        $organization = Organization::factory()->create();
        $user = $this->owner($organization);

        $this->actingAs($user)->post('/organizations/subscribe', [
            'plan' => 'monthly',
            'amount' => 1, // attempted override - the controller has no such input
        ]);

        $this->assertDatabaseHas('invoices', ['amount' => 150000]);
    }

    /**
     * Tenant isolation: the organization charged is always
     * OrganizationContext::current(), never anything the client could name
     * - there is no organization_id field in the request at all, so a
     * malicious payload naming a different organization simply has no
     * effect; the checkout always lands on the acting user's own current
     * organization.
     */
    public function test_checkout_always_targets_the_actors_own_current_organization(): void
    {
        $this->fakeSuccessfulDoku();
        $myOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = $this->owner($myOrganization);

        $this->actingAs($user)->post('/organizations/subscribe', [
            'plan' => 'monthly',
            'organization_id' => $otherOrganization->id, // attempted override - no such input either
        ]);

        $this->assertDatabaseHas('invoices', ['organization_id' => $myOrganization->id]);
        $this->assertDatabaseMissing('invoices', ['organization_id' => $otherOrganization->id]);
    }

    public function test_an_unknown_plan_code_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->owner($organization);

        $response = $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'does-not-exist']);

        $response->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_doku_failure_rolls_back_the_invoice_and_shows_an_error(): void
    {
        Http::fake([
            '*/checkout/v1/payment' => Http::response(['error_messages' => ['Bad request']], 400),
        ]);
        $organization = Organization::factory()->create();
        $user = $this->owner($organization);

        $response = $this->actingAs($user)->post('/organizations/subscribe', ['plan' => 'monthly']);

        $response->assertSessionHasErrors('plan');
        // The transaction (App\Services\SubscriptionCheckoutService) rolls
        // back the whole thing on a DokuPaymentException - no orphaned
        // pending invoice with no payment URL is left behind.
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_dokupaymentexception_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new DokuPaymentException('x'));
    }
}

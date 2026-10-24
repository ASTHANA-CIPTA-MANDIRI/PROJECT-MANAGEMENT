<?php

namespace App\Http\Controllers;

use App\Services\Doku\DokuPaymentException;
use App\Services\SubscriptionCheckoutService;
use App\Support\OrganizationContext;
use App\Support\SubscriptionPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Fase 8 - starts a DOKU Checkout for the acting user's own current
 * Organization. Only ever two pieces of client input: which plan code was
 * picked, nothing else - the amount, the Organization being charged, and
 * the authorization to charge it are all resolved server-side:
 *
 *  - Organization: App\Support\OrganizationContext::current(), never a
 *    posted organization_id (CLAUDE.md's "every client-supplied id is
 *    re-resolved through an access-scoped query" rule - here there isn't
 *    even a client-supplied id to begin with, by design).
 *  - Amount: App\Support\SubscriptionPlans::find($planCode), reading
 *    config('subscriptions.plans') - never anything from the request body.
 *  - Authorization: OrganizationPolicy::update() (Owner/Admin only, the
 *    same "can manage this Organization" gate every other Organization-
 *    settings action in this app already uses - see OrganizationSettings.php).
 */
class SubscriptionCheckoutController extends Controller
{
    public function store(Request $request, SubscriptionCheckoutService $service): RedirectResponse
    {
        // The same switch that hides the buy buttons (config/subscriptions.php):
        // the UI being hidden is not enough, the endpoint itself must not create
        // an invoice or call DOKU while checkout is off (e.g. placeholder prices
        // in production). First thing, before validation or any lookup.
        abort_unless(config('subscriptions.checkout_enabled'), 404);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(SubscriptionPlans::codes())],
        ]);

        $organization = OrganizationContext::current($request->user());

        abort_if($organization === null, 403, 'No active organization.');

        Gate::authorize('update', $organization);

        try {
            $invoice = $service->checkout($organization, $data['plan']);
        } catch (DokuPaymentException $e) {
            return back()->withErrors([
                'plan' => __('Failed to reach DOKU, please try again.'),
            ]);
        }

        return redirect()->away($invoice->doku_payment_url);
    }
}

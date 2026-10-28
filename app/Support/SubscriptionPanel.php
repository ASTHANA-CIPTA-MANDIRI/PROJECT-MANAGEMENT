<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything the subscription UI shows about one Organization, in one place so
 * the "Subscription" panel on /organization and the billing page
 * (/organization/billing) can never disagree about it. Read-only: it only ever
 * looks at the Organization it is handed (callers pass the user's own current
 * Organization from OrganizationContext, never a posted id).
 */
class SubscriptionPanel
{
    /**
     * Two small queries beyond the summary card (active subscriptions, one
     * unfinished invoice). Plans, prices and the "continue payment" link are
     * Owner/Admin only and also need subscriptions.checkout_enabled - a plain
     * Member gets the status rows and nothing else.
     *
     * @return array{
     *     paid: bool,
     *     paidUntil: ?Carbon,
     *     unlimited: bool,
     *     trial: array{label: string, tone: string},
     *     trialEndsAt: ?Carbon,
     *     locked: bool,
     *     canManage: bool,
     *     checkoutEnabled: bool,
     *     plans: array<string, array{name: string, amount: int, duration_days: int}>,
     *     pendingInvoice: ?Invoice
     * }
     */
    public static function for(Organization $organization, User $user): array
    {
        $canManage = $organization->isManageableBy($user);
        $checkoutEnabled = $canManage && (bool) config('subscriptions.checkout_enabled');

        $active = $organization->subscriptions()->active()->get(['id', 'ends_at']);
        $paid = $active->isNotEmpty();
        // An open-ended period anywhere among the active ones means there is
        // nothing to renew; otherwise renewal continues from the latest end.
        $unlimited = $active->contains(fn ($subscription) => $subscription->ends_at === null);

        return [
            'paid' => $paid,
            'paidUntil' => $paid && ! $unlimited ? $active->max('ends_at') : null,
            'unlimited' => $paid && $unlimited,
            'trial' => self::trialStatus($organization),
            'trialEndsAt' => $organization->trial_ends_at,
            'locked' => ! $paid && ! TrialGate::active($organization),
            'canManage' => $canManage,
            'checkoutEnabled' => $checkoutEnabled,
            'plans' => $checkoutEnabled ? SubscriptionPlans::sellable() : [],
            'pendingInvoice' => $checkoutEnabled
                ? $organization->invoices()
                    ->where('status', Invoice::STATUS_PENDING)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereNotNull('doku_payment_url')
                    ->where('doku_payment_url', '!=', '')
                    ->latest('id')
                    ->first()
                : null,
        ];
    }

    /**
     * The trial clock on its own, ignoring any paid subscription - the one
     * place both the summary card (which layers "Subscribed" on top) and the
     * subscription UI (which shows trial and paid as separate facts) read it
     * from, so the two can never disagree about it.
     *
     * @return array{label: string, tone: string}
     */
    public static function trialStatus(Organization $organization): array
    {
        $trialEnds = $organization->trial_ends_at;

        return match (true) {
            $trialEnds === null => ['label' => __('No time limit'), 'tone' => 'green'],
            $trialEnds->isFuture() => [
                'label' => trans_choice(':count day left|:count days left', max(1, (int) ceil(now()->floatDiffInDays($trialEnds))), ['count' => max(1, (int) ceil(now()->floatDiffInDays($trialEnds)))]),
                'tone' => 'yellow',
            ],
            default => ['label' => __('Trial ended'), 'tone' => 'red'],
        };
    }

    /** IDR, no decimals - the same unit DOKU's order.amount and invoices.amount use. */
    public static function formatRupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}

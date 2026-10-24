<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Support\OrganizationContext;
use App\Support\SubscriptionPanel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The page a locked Organization is sent to (see
 * App\Http\Middleware\RedirectLockedOrganizationToBilling), and also a plain
 * "plans" page anyone in an Organization may open: one product, two
 * durations (config('subscriptions.plans')), no tiers.
 *
 * No page permission, same as OrganizationSettings: every member may open it
 * (they need somewhere to land when the trial ends), but only Owner/Admin
 * (SubscriptionPanel's canManage) get the card, the prices and the buy
 * button - a plain Member gets the status and a "ask an owner or admin"
 * message. The checkout itself is the existing plain form POST to
 * organizations.subscribe, which re-authorizes (OrganizationPolicy::update())
 * and re-resolves both the Organization and the price server-side.
 *
 * Not in the sidebar: it is reached through the paywall redirect and the link
 * on the Organization settings "Subscription" panel.
 */
class Billing extends AuthorizedPage
{
    protected static ?string $slug = 'organization/billing';

    protected static string $view = 'filament.pages.billing';

    protected static bool $shouldRegisterNavigation = false;

    private function organization(): ?Organization
    {
        return OrganizationContext::current(auth()->user());
    }

    /**
     * A user without any Organization has nothing to pay for: same 404 as
     * OrganizationSettings::mount().
     */
    public function mount(): void
    {
        abort_unless($this->organization() !== null, 404);
    }

    protected function getTitle(): string
    {
        return __('Subscription');
    }

    /**
     * No visible page header on this page: an empty heading makes Filament skip
     * the header block entirely (components/page.blade.php), and the breadcrumb
     * trail is emptied explicitly. The browser tab keeps its meaning through
     * getTitle(), and the view carries its own screen-reader-only <h1>.
     */
    protected function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<string, string> */
    protected function getBreadcrumbs(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function panel(): array
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 404);

        return SubscriptionPanel::for($organization, auth()->user());
    }

    /**
     * How much cheaper a day of the yearly plan is than a day of the monthly
     * plan, computed from the configured prices and
     * durations only (nothing hardcoded). Null when it is not cheaper or
     * either plan is missing.
     *
     * @param  array<string, array{name: string, amount: int, duration_days: int}>  $plans
     */
    public function yearlySavingsPercent(array $plans): ?int
    {
        $monthly = $plans['monthly'] ?? null;
        $yearly = $plans['yearly'] ?? null;

        if ($monthly === null || $yearly === null || $monthly['amount'] < 1 || $monthly['duration_days'] < 1 || $yearly['duration_days'] < 1) {
            return null;
        }

        $perDayMonthly = $monthly['amount'] / $monthly['duration_days'];
        $perDayYearly = $yearly['amount'] / $yearly['duration_days'];

        $percent = (int) round((1 - $perDayYearly / $perDayMonthly) * 100);

        return $percent > 0 ? $percent : null;
    }
}

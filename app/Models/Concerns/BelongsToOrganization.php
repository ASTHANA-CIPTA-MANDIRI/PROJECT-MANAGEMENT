<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 3B — shared by every reference-data model each Organization now owns
 * its own copy of: TicketType, TicketPriority, Label, Activity, ProjectStatus.
 * Before this phase these were single global tables shared by every
 * Organization in the installation (the exact leak the round-10 audit and
 * the roadmap's Fase 3B both named).
 *
 * organization_id === null is legacy/pre-Fase-3B data (or a row created
 * outside any authenticated Organization context — console/seeder/tinker)
 * and is deliberately exempt from the organization match, not a new escape
 * hatch — this mirrors Project::isWithinOrganizationContext()'s identical
 * rule for the exact same reason, extended here for consistency rather
 * than invented fresh.
 *
 * Deliberately NOT trial-gated (unlike AuthServiceProvider::TRIAL_GATED_MODELS):
 * this trait solves tenant *isolation* only ("does org A ever see org B's
 * reference data"), a separate concern from *subscription* enforcement
 * ("can this org use the app at all"). Folding TrialGate in here was
 * considered and deferred — out of scope for Fase 3B, a decision for
 * whoever revisits it, not an oversight.
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Rows visible to this user: the Organization-less legacy rows, plus
     * this user's own current Organization's rows. The single source of
     * truth for every listing/dropdown built on this model — mirrors
     * Project::scopeAccessibleBy()'s shape for the same reason.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $currentOrganization = OrganizationContext::current($user);

        return $query->where(fn (Builder $query) => $query->whereNull('organization_id')
            ->when(
                $currentOrganization !== null,
                fn (Builder $query) => $query->orWhere('organization_id', $currentOrganization->id)
            ));
    }

    /**
     * The single-instance twin of scopeVisibleTo() above — Policies ask
     * this about a model they already hold, where a query scope would have
     * nothing to filter.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->organization_id === null) {
            return true;
        }

        return $this->organization_id === OrganizationContext::current($user)?->id;
    }
}

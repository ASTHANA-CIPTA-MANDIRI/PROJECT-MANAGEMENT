<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Epic;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\EpicPolicy;
use App\Policies\LabelPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectStatusPolicy;
use App\Policies\RolePolicy;
use App\Policies\SprintPolicy;
use App\Policies\TicketCommentPolicy;
use App\Policies\TicketHourPolicy;
use App\Policies\TicketPolicy;
use App\Policies\TicketPriorityPolicy;
use App\Policies\TicketStatusPolicy;
use App\Policies\TicketTypePolicy;
use App\Policies\UserPolicy;
use App\Support\OrganizationContext;
use App\Support\TrialGate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * Registered explicitly rather than leaning on Laravel's naming convention:
     * this list is the single place to see which models are guarded. It matters
     * because an unguarded model fails open - Filament's Resource::can() returns
     * true when Gate::getPolicyFor() finds no policy. PolicyRegistrationTest
     * keeps this map in sync with app/Models and app/Policies.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Activity::class => ActivityPolicy::class,
        Epic::class => EpicPolicy::class,
        Label::class => LabelPolicy::class,
        Organization::class => OrganizationPolicy::class,
        Permission::class => PermissionPolicy::class,
        Project::class => ProjectPolicy::class,
        ProjectStatus::class => ProjectStatusPolicy::class,
        Role::class => RolePolicy::class,
        Sprint::class => SprintPolicy::class,
        Ticket::class => TicketPolicy::class,
        TicketComment::class => TicketCommentPolicy::class,
        TicketHour::class => TicketHourPolicy::class,
        TicketPriority::class => TicketPriorityPolicy::class,
        TicketStatus::class => TicketStatusPolicy::class,
        TicketType::class => TicketTypePolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Fase 6 — the models a locked-out Organization (trial expired, not
     * subscribed) loses every ability on, read and write alike, regardless
     * of what the underlying Policy would otherwise decide. Deliberately
     * excludes Organization itself: a locked-out Owner must still be able
     * to reach the Organization/billing pages to subscribe and unlock
     * everything else — see denyIfOrganizationLocked() below.
     *
     * @var array<int, class-string>
     */
    private const TRIAL_GATED_MODELS = [
        Project::class,
        Ticket::class,
        Sprint::class,
        Epic::class,
        TicketComment::class,
        TicketHour::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Make Filament authorization fail closed. By default both Resource::can()
        // and RelationManager::can() return true when the model has no policy, or
        // when the policy has no method for the ability being checked - so an
        // ability nobody wrote a method for is granted to everyone. Both then fall
        // through to the very same Gate::check() call we force here, so this only
        // removes the two "allow by default" shortcuts; it does not change how any
        // existing decision is computed. FilamentAuthorizationTest pins the
        // abilities the panel actually asks for to real policy methods.
        Resource::authorizeWithGate();
        RelationManager::authorizeWithGate();

        Gate::before(fn (User $user, string $ability, array $arguments = []) => $this->denyIfOrganizationLocked($user, $arguments));
    }

    /**
     * Fase 6 enforcement. Runs before every Policy check in the app —
     * including raw permission-string checks like $user->can('View project'),
     * which arrive here with an empty $arguments array and are skipped, same
     * as any ability on a model outside TRIAL_GATED_MODELS. Returning null
     * (not true/Response::allow()) lets the real Policy decide exactly as it
     * did before this phase; only a genuinely locked Organization ever
     * returns a deny Response here.
     *
     * @return \Illuminate\Auth\Access\Response|null
     */
    private function denyIfOrganizationLocked(User $user, array $arguments)
    {
        $target = $arguments[0] ?? null;
        $targetClass = is_string($target) ? $target : ($target !== null ? get_class($target) : null);

        if ($targetClass === null || ! in_array($targetClass, self::TRIAL_GATED_MODELS, true)) {
            return null;
        }

        // Audit finding (2026-09-09): a legacy/pre-tenant Project
        // (organization_id === null) must never be gated by this check no
        // matter what the acting user's own active Organization is doing —
        // Project::isWithinOrganizationContext() already documents this
        // guarantee at the Policy layer, and this Gate::before ran in
        // front of it unconditionally before this fix, which could wrongly
        // lock a user out of a project that has nothing to do with
        // whichever Organization they currently have selected. Checked
        // directly off the instance (already-loaded attribute, no extra
        // query) rather than resolved generically for every gated model —
        // the other five models reach their Organization only through a
        // `project` relation that usually isn't eager-loaded on this path,
        // and would turn this single Gate callback into an N+1 on every
        // ticket/sprint/epic/comment/hour ability check.
        if ($target instanceof Project && $target->organization_id === null) {
            return null;
        }

        // No Organization in play at all (a legacy/grandfathered user with
        // no membership, or Super Admin acting outside any Organization) —
        // nothing to gate; unchanged from before this phase.
        $organization = OrganizationContext::current($user);

        if ($organization === null) {
            return null;
        }

        if (TrialGate::active($organization)) {
            return null;
        }

        return Response::deny(__('Your organization\'s trial has ended. Contact the organization Owner to subscribe.'));
    }
}

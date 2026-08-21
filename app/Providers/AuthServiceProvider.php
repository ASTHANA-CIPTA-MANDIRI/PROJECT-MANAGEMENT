<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Epic;
use App\Models\Label;
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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

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
    }
}

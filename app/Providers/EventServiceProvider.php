<?php

namespace App\Providers;

use App\Listeners\AssignDefaultRole;
use App\Listeners\NotifyAdminsOfRegistration;
use App\Listeners\SocialRegistration;
use App\Models\ProjectStatus;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Observers\ProjectStatusObserver;
use App\Observers\SprintObserver;
use App\Observers\TicketCommentObserver;
use App\Observers\TicketObserver;
use App\Observers\TicketPriorityObserver;
use App\Observers\TicketStatusObserver;
use App\Observers\TicketTypeObserver;
use App\Observers\UserObserver;
use DutchCodingCompany\FilamentSocialite\Events\Registered as SocialRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
            AssignDefaultRole::class,
            NotifyAdminsOfRegistration::class,
        ],
        SocialRegistered::class => [
            SocialRegistration::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Ticket::observe(TicketObserver::class);
        TicketComment::observe(TicketCommentObserver::class);
        Sprint::observe(SprintObserver::class);
        TicketStatus::observe(TicketStatusObserver::class);
        ProjectStatus::observe(ProjectStatusObserver::class);
        TicketType::observe(TicketTypeObserver::class);
        TicketPriority::observe(TicketPriorityObserver::class);
        User::observe(UserObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}

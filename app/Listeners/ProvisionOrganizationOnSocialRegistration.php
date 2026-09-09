<?php

namespace App\Listeners;

use App\Listeners\Concerns\ProvisionsPersonalOrganization;
use App\Models\User;
use DutchCodingCompany\FilamentSocialite\Events\Registered;

/**
 * Fase 6 — social-login counterpart of App\Listeners\SocialRegistration,
 * same reasoning as ProvisionOrganizationOnRegistration: a separate
 * listener class for a separate concern, sharing only the trait.
 */
class ProvisionOrganizationOnSocialRegistration
{
    use ProvisionsPersonalOrganization;

    /**
     * $event->socialiteUser->user is typed Model|null — the instanceof
     * narrows it instead of adding a PHPStan baseline entry, same
     * reasoning as ProvisionOrganizationOnRegistration::handle().
     */
    public function handle(Registered $event): void
    {
        if ($event->socialiteUser->user instanceof User) {
            $this->provisionPersonalOrganization($event->socialiteUser->user);
        }
    }
}

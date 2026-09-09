<?php

namespace App\Listeners;

use App\Listeners\Concerns\ProvisionsPersonalOrganization;
use DutchCodingCompany\FilamentSocialite\Events\Registered;

/**
 * Fase 6 — social-login counterpart of App\Listeners\SocialRegistration,
 * same reasoning as ProvisionOrganizationOnRegistration: a separate
 * listener class for a separate concern, sharing only the trait.
 */
class ProvisionOrganizationOnSocialRegistration
{
    use ProvisionsPersonalOrganization;

    public function handle(Registered $event): void
    {
        $this->provisionPersonalOrganization($event->socialiteUser->user);
    }
}

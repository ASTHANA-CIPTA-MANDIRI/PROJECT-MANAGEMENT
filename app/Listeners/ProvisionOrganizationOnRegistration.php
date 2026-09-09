<?php

namespace App\Listeners;

use App\Listeners\Concerns\ProvisionsPersonalOrganization;
use Illuminate\Auth\Events\Registered;

/**
 * Fase 6 — self-registration counterpart of App\Listeners\AssignDefaultRole,
 * kept as its own listener class (same event, separate concern) rather than
 * folded into that one, mirroring how role-assignment and Organization
 * provisioning are two independent things a fresh signup needs.
 */
class ProvisionOrganizationOnRegistration
{
    use ProvisionsPersonalOrganization;

    public function handle(Registered $event): void
    {
        $this->provisionPersonalOrganization($event->user);
    }
}

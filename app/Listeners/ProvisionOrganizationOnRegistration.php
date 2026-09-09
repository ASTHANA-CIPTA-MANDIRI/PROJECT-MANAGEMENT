<?php

namespace App\Listeners;

use App\Listeners\Concerns\ProvisionsPersonalOrganization;
use App\Models\User;
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

    /**
     * $event->user is typed Authenticatable (Laravel's built-in event) —
     * the instanceof narrows it to the concrete model instead of adding a
     * PHPStan baseline entry for it, unlike the pre-existing
     * AssignDefaultRole/SocialRegistration listeners this mirrors, which
     * do carry that baseline entry. A guard clause here is just as cheap
     * and keeps the baseline from growing further for genuinely new code.
     */
    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->provisionPersonalOrganization($event->user);
        }
    }
}

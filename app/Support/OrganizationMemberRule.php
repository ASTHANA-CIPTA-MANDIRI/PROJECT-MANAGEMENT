<?php

namespace App\Support;

use App\Models\Organization;
use Closure;

/**
 * Validates that a submitted user id is a member of the given Organization —
 * the server-side backstop for an Organization-scoped picker (Phase 5.3B:
 * ProjectForm's owner_id), since a Select's ->options() list is a UI
 * convenience only and is never enough on its own — a crafted payload can
 * submit any id regardless of what was actually rendered.
 *
 * Passes automatically when there is no Organization to scope by (a legacy
 * null-organization project, Phase 3A/5.3B): there is nothing to validate
 * against, and that flow stays exactly as ungated as it always has been.
 */
class OrganizationMemberRule
{
    public static function make(?Organization $organization, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($organization, $message): void {
            if ($organization === null) {
                return;
            }

            if (! $organization->users()->whereKey($value)->exists()) {
                $fail($message);
            }
        };
    }
}

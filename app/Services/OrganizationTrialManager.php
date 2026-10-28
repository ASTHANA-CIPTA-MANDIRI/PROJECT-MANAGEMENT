<?php

namespace App\Services;

use App\Models\BillingAuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only writer of organizations.trial_ends_at for a HUMAN (Super Admin)
 * action. trial_ends_at decides whether an organization is locked out
 * (App\Support\TrialGate), so every change is recorded in billing_audit_logs
 * in the SAME transaction as the change itself: if the audit row cannot be
 * written the trial is not changed (fail-closed, like PlanManager).
 *
 * Deliberately NOT routed through here: the 7-day trial that registration
 * gives a self-registered user (ProvisionsPersonalOrganization). That is the
 * system acting on a new account, there is no actor to attribute, and
 * auditing every sign-up would bury the manual changes this trail exists for.
 *
 * Audit values: old_value / new_value are the trial end as ISO 8601 UTC
 * ("2026-11-04T00:00:00Z"), or the literal "unlimited" for trial_ends_at =
 * NULL (grandfathered, never locked). A SQL NULL old_value means "there was
 * no previous value": the organization was created with this trial.
 */
class OrganizationTrialManager
{
    public const REASON_MAX_LENGTH = 500;

    public const UNLIMITED = 'unlimited';

    public static function reasonIsValid(?string $reason): bool
    {
        return mb_strlen(trim((string) $reason)) <= self::REASON_MAX_LENGTH;
    }

    /**
     * @param  string|null  $trialEndsAt  the submitted date (blank = unlimited)
     * @return bool whether the trial changed (false = nothing written, no audit row)
     *
     * @throws TrialChangeException
     */
    public function change(User $actor, Organization $organization, ?string $trialEndsAt, ?string $reason = null): bool
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $reason = $this->cleanReason($reason);
        $new = $this->parse($trialEndsAt);

        try {
            return DB::transaction(function () use ($actor, $organization, $new, $reason): bool {
                // Re-read and locked: the model the caller holds may be stale,
                // and the audit's "old" must be what is really being replaced.
                $locked = Organization::query()->lockForUpdate()->find($organization->getKey());

                if ($locked === null) {
                    throw new TrialChangeException(__('This organization no longer exists.'));
                }

                $old = $locked->trial_ends_at;

                if ($this->sameTrial($old, $new)) {
                    return false;
                }

                $locked->forceFill(['trial_ends_at' => $new])->save();

                $this->record($actor, $locked, $this->format($old), $this->format($new), $reason);

                $organization->setRawAttributes($locked->getAttributes(), true);

                return true;
            });
        } catch (TrialChangeException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new TrialChangeException(__('The trial could not be changed. Nothing was saved, please try again.'), 0, $e);
        }
    }

    /**
     * Audit the trial a Super Admin gave an organization when creating it. Call
     * it inside the transaction that creates the organization so a failed audit
     * row also undoes the creation.
     */
    public function recordInitial(User $actor, Organization $organization): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $this->record($actor, $organization, null, $this->format($organization->trial_ends_at), null);
    }

    private function record(User $actor, Organization $organization, ?string $old, string $new, ?string $reason): void
    {
        BillingAuditLog::record(
            BillingAuditLog::ACTION_ORGANIZATION_TRIAL_UPDATE,
            'success',
            null,
            $actor,
            $reason,
            subject: 'organization:'.$organization->getKey(),
            oldValue: $old,
            newValue: $new,
            organizationId: $organization->getKey(),
        );
    }

    private function parse(?string $date): ?Carbon
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (Throwable) {
            throw new TrialChangeException(__('The trial end date is not a valid date.'));
        }
    }

    /**
     * The form only holds a DATE. Re-saving an unrelated edit (a rename) submits
     * the stored day again, which must not shift the stored moment to midnight
     * nor count as a change - so the same calendar day is "unchanged".
     */
    private function sameTrial(?Carbon $old, ?Carbon $new): bool
    {
        if ($old === null || $new === null) {
            return $old === null && $new === null;
        }

        return $old->copy()->setTimezone($new->getTimezone())->isSameDay($new);
    }

    private function format(?Carbon $date): string
    {
        return $date === null ? self::UNLIMITED : $date->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Blank means "no reason given" (null); too long is refused. */
    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        if (! self::reasonIsValid($reason)) {
            throw new TrialChangeException(__('The reason may be at most :max characters.', ['max' => self::REASON_MAX_LENGTH]));
        }

        return $reason === '' ? null : $reason;
    }
}

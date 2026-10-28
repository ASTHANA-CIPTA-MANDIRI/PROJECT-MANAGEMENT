<?php

namespace App\Services;

use App\Models\BillingAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The only writer of subscription_plans. Every create / edit / (de)activate
 * by a Super Admin goes through here and does, in ONE database transaction:
 * the change AND its billing_audit_logs row. If the audit row cannot be
 * written, the change is rolled back (fail-closed): there is never a price
 * change nobody can later explain.
 *
 * What it guarantees on top of the page's own form validation (the page is
 * a UI, this is the boundary):
 *  - the actor is a Super Admin;
 *  - an OPTIONAL reason (at most 500 characters after trimming; blank is stored
 *    as null) - the audit row is written either way, with who, when and old -> new;
 *  - amount / duration / name / code are validated against the config bounds;
 *  - `code` is set on creation only, a plan is never deleted;
 *  - the last active plan is not deactivated while checkout is enabled;
 *  - a submit that changes nothing writes nothing (no empty audit row).
 *
 * Changing a plan only affects FUTURE checkouts: an invoice froze its own
 * amount, duration_days and plan_name when it was created.
 */
class PlanManager
{
    public const REASON_MAX_LENGTH = 500;

    public const NAME_MAX_LENGTH = 60;

    /** The editable fields, in the order they are audited. */
    public const TRACKED = ['name', 'amount', 'duration_days', 'is_active', 'sort_order'];

    /**
     * Validation rules per field. Shared with the Plans page's form so the UI
     * and this service can never disagree about a bound.
     *
     * @return array<int, mixed>
     */
    public static function rulesFor(string $field): array
    {
        return match ($field) {
            'code' => ['required', 'string', 'regex:'.SubscriptionPlan::CODE_PATTERN, Rule::unique('subscription_plans', 'code')],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'amount' => ['required', 'integer', 'min:'.(int) config('subscriptions.min_amount'), 'max:'.(int) config('subscriptions.max_amount')],
            'duration_days' => ['required', 'integer', 'min:'.(int) config('subscriptions.min_duration_days'), 'max:'.(int) config('subscriptions.max_duration_days')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
            default => throw new \InvalidArgumentException("Unknown plan field [{$field}]."),
        };
    }

    public static function reasonIsValid(?string $reason): bool
    {
        return mb_strlen(trim((string) $reason)) <= self::REASON_MAX_LENGTH;
    }

    /**
     * @param  array<string, mixed>  $data  untrusted form input, validated here
     *
     * @throws PlanChangeException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(User $actor, array $data, ?string $reason = null): SubscriptionPlan
    {
        $this->authorize($actor);
        $reason = $this->cleanReason($reason);

        $data = Validator::make([
            'code' => trim((string) ($data['code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'amount' => $data['amount'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ], collect(['code', ...self::TRACKED])->mapWithKeys(fn (string $f) => [$f => self::rulesFor($f)])->all())->validate();

        return DB::transaction(function () use ($actor, $data, $reason) {
            $plan = SubscriptionPlan::create($this->normalize($data) + ['code' => $data['code']]);

            BillingAuditLog::record(
                BillingAuditLog::ACTION_PLAN_CREATE,
                'success',
                null,
                $actor,
                $reason,
                subject: $this->subject($plan),
                newValue: $this->snapshot($plan),
            );

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $data  untrusted form input, validated here
     * @return bool whether anything changed (false = nothing written, no audit row)
     *
     * @throws PlanChangeException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(User $actor, SubscriptionPlan $plan, array $data, ?string $reason = null): bool
    {
        $this->authorize($actor);
        $reason = $this->cleanReason($reason);

        $data = Validator::make([
            'name' => trim((string) ($data['name'] ?? '')),
            'amount' => $data['amount'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], collect(self::TRACKED)->mapWithKeys(fn (string $f) => [$f => self::rulesFor($f)])->all())->validate();

        return $this->change($actor, $plan, $this->normalize($data), $reason, BillingAuditLog::ACTION_PLAN_UPDATE);
    }

    /**
     * @return bool whether anything changed (false when already in that state)
     *
     * @throws PlanChangeException
     */
    public function setActive(User $actor, SubscriptionPlan $plan, bool $active, ?string $reason = null): bool
    {
        $this->authorize($actor);
        $reason = $this->cleanReason($reason);

        return $this->change($actor, $plan, ['is_active' => $active], $reason, BillingAuditLog::ACTION_PLAN_TOGGLE);
    }

    /**
     * @param  array<string, mixed>  $changes  already validated, a subset of TRACKED
     */
    private function change(User $actor, SubscriptionPlan $plan, array $changes, ?string $reason, string $action): bool
    {
        return DB::transaction(function () use ($actor, $plan, $changes, $reason, $action): bool {
            // Re-resolved and locked: the row the caller holds may be stale, and
            // two Super Admins editing the same plan must not interleave.
            $locked = SubscriptionPlan::query()->lockForUpdate()->find($plan->getKey());

            if ($locked === null) {
                throw new PlanChangeException(__('This plan no longer exists.'));
            }

            $old = $this->snapshot($locked);
            $locked->fill($changes);

            if (! $locked->isDirty()) {
                return false;
            }

            if ($locked->isDirty('is_active') && ! $locked->is_active) {
                $this->assertAnotherPlanStaysActive($locked);
            }

            $locked->save();

            BillingAuditLog::record(
                $action,
                'success',
                null,
                $actor,
                $reason,
                subject: $this->subject($locked),
                oldValue: $old,
                newValue: $this->snapshot($locked),
            );

            return true;
        });
    }

    /**
     * Deactivating the last sellable plan while checkout is on would leave the
     * billing page empty and make every checkout fail, with no sign of why.
     * Refused with a clear message instead (see the Plans page); with checkout
     * switched off nothing is being sold anyway, so it is allowed then.
     * The active rows are locked so two concurrent deactivations cannot both
     * see "the other one is still active".
     */
    private function assertAnotherPlanStaysActive(SubscriptionPlan $plan): void
    {
        if (! config('subscriptions.checkout_enabled')) {
            return;
        }

        $others = SubscriptionPlan::query()->active()->whereKeyNot($plan->getKey())->lockForUpdate()->count();

        if ($others === 0) {
            throw new PlanChangeException(__('This is the last active plan. Deactivating it would leave nothing to buy while checkout is enabled. Activate another plan first, or turn checkout off.'));
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);
    }

    /** Blank means "no reason given" (null); too long is refused. */
    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        if (! self::reasonIsValid($reason)) {
            throw new PlanChangeException(__('The reason may be at most :max characters.', ['max' => self::REASON_MAX_LENGTH]));
        }

        return $reason === '' ? null : $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'amount' => (int) $data['amount'],
            'duration_days' => (int) $data['duration_days'],
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) $data['sort_order'],
        ];
    }

    private function subject(SubscriptionPlan $plan): string
    {
        return 'plan:'.$plan->code;
    }

    /** Compact JSON of the editable fields, the unit the audit trail compares. */
    private function snapshot(SubscriptionPlan $plan): string
    {
        return (string) json_encode(
            collect(self::TRACKED)->mapWithKeys(fn (string $field) => [$field => $plan->{$field}])->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}

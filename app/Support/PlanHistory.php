<?php

namespace App\Support;

use App\Models\BillingAuditLog;
use App\Models\SubscriptionPlan;
use Illuminate\Support\HtmlString;

/**
 * Presentation of subscription-plan changes for the Plans resource: the
 * "Price: Rp150.000 -> Rp200.000" summary shown while editing, and the audit
 * history under the edit form. Pure formatting plus one read; every value that
 * reaches HTML is escaped by the caller (Blade) or here (diffHtml).
 */
class PlanHistory
{
    /** @return array<string, string> */
    public static function fieldLabels(): array
    {
        return [
            'name' => __('Name'),
            'amount' => __('Price'),
            'duration_days' => __('Duration'),
            'is_active' => __('Status'),
            'sort_order' => __('Order'),
        ];
    }

    public static function displayValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($field) {
            'amount' => is_numeric($value) ? SubscriptionPanel::formatRupiah((int) $value) : (string) $value,
            'duration_days' => is_numeric($value) ? trans_choice(':count day|:count days', (int) $value, ['count' => (int) $value]) : (string) $value,
            'is_active' => $value ? __('Active') : __('Inactive'),
            default => (string) $value,
        };
    }

    /**
     * Live summary of what the form would change, old value struck through.
     * All text is escaped (the name is Super Admin input).
     *
     * @param  array<string, mixed>  $new
     */
    public static function diffHtml(?SubscriptionPlan $plan, array $new): HtmlString
    {
        if ($plan === null) {
            return new HtmlString('');
        }

        $lines = [];

        foreach (self::fieldLabels() as $field => $label) {
            $before = $plan->{$field};
            $after = $new[$field] ?? null;

            if (in_array($field, ['amount', 'duration_days', 'sort_order'], true) && is_numeric($after)) {
                $after = (int) $after;
            }

            if ($before === $after) {
                continue;
            }

            $lines[] = '<li><b>'.e($label).'</b><span class="rk-plans__old">'.e(self::displayValue($field, $before)).'</span><span class="rk-plans__arrow" aria-hidden="true">&rarr;</span><span class="rk-plans__new">'.e(self::displayValue($field, $after)).'</span></li>';
        }

        return new HtmlString($lines === []
            ? '<span class="rk-plans__muted">'.e(__('No changes yet.')).'</span>'
            : '<ul class="rk-plans__diff">'.implode('', $lines).'</ul>');
    }

    /**
     * Latest 50 audit rows of one plan: one query plus one eager-loaded actor
     * query, however many rows. Plain strings for Blade to escape.
     *
     * @return array<int, array{when: ?string, actor: string, action: string, reason: ?string, changes: array<int, string>}>
     */
    public static function rows(SubscriptionPlan $plan): array
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $timezone = config('app.display_timezone');

        return BillingAuditLog::query()
            ->where('subject', 'plan:'.$plan->code)
            ->whereIn('action', BillingAuditLog::PLAN_ACTIONS)
            ->with('actor:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (BillingAuditLog $log) => [
                'when' => $log->created_at?->timezone($timezone)->format('d M Y H:i:s'),
                'actor' => $log->actor?->name ?? '-',
                'action' => match ($log->action) {
                    BillingAuditLog::ACTION_PLAN_CREATE => __('Plan added'),
                    BillingAuditLog::ACTION_PLAN_TOGGLE => __('Status changed'),
                    default => __('Plan edited'),
                },
                'reason' => $log->reason,
                'changes' => self::changes($log->old_value, $log->new_value),
            ])
            ->all();
    }

    /**
     * Old/new JSON snapshots as "Price: Rp150.000 -> Rp200.000" lines. A
     * creation (no old snapshot) lists the initial values. Malformed JSON
     * yields no lines, never an exception.
     *
     * @return array<int, string>
     */
    public static function changes(?string $oldJson, ?string $newJson): array
    {
        $old = $oldJson === null ? null : json_decode($oldJson, true);
        $new = $newJson === null ? null : json_decode($newJson, true);

        if (! is_array($new)) {
            return [];
        }

        $lines = [];

        foreach (self::fieldLabels() as $field => $label) {
            if (! array_key_exists($field, $new) || ! is_scalar($new[$field])) {
                continue;
            }

            if (! is_array($old)) {
                $lines[] = $label.': '.self::displayValue($field, $new[$field]);

                continue;
            }

            if (($old[$field] ?? null) !== $new[$field]) {
                $lines[] = $label.': '.self::displayValue($field, $old[$field] ?? null).' -> '.self::displayValue($field, $new[$field]);
            }
        }

        return $lines;
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAuditLog;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanChangeException;
use App\Services\PlanManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The only writer of subscription_plans: validation, the audit row written
 * in the same transaction (fail-closed), the last-active-plan guard.
 */
class PlanManagerTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Harga baru sesuai keputusan tim bisnis';

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::firstOrCreate(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    private function manager(): PlanManager
    {
        return app(PlanManager::class);
    }

    private function monthly(): SubscriptionPlan
    {
        return SubscriptionPlan::where('code', 'monthly')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function values(array $override = []): array
    {
        return $override + ['name' => 'Monthly', 'amount' => 150000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 1];
    }

    // ----------------------------------------------------------- audit

    public function test_an_update_changes_the_plan_and_writes_one_audit_row_with_old_and_new_values_and_the_reason(): void
    {
        $admin = $this->superAdmin();

        $changed = $this->manager()->update($admin, $this->monthly(), $this->values(['amount' => 175000, 'duration_days' => 31]), '  '.self::REASON.'  ');

        $this->assertTrue($changed);
        $plan = $this->monthly();
        $this->assertSame([175000, 31], [$plan->amount, $plan->duration_days]);

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_PLAN_UPDATE, $log->action);
        $this->assertSame('plan:monthly', $log->subject);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame(self::REASON, $log->reason, 'trimmed');
        $this->assertNull($log->invoice_id);
        $this->assertSame(150000, json_decode($log->old_value, true)['amount']);
        $this->assertSame(175000, json_decode($log->new_value, true)['amount']);
        $this->assertSame(30, json_decode($log->old_value, true)['duration_days']);
        $this->assertSame(31, json_decode($log->new_value, true)['duration_days']);
    }

    public function test_create_writes_a_create_row_with_only_a_new_value(): void
    {
        $plan = $this->manager()->create($this->superAdmin(), ['code' => 'quarterly'] + $this->values(['name' => 'Quarterly', 'amount' => 400000, 'duration_days' => 90]), self::REASON);

        $this->assertSame('quarterly', $plan->code);
        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_PLAN_CREATE, $log->action);
        $this->assertSame('plan:quarterly', $log->subject);
        $this->assertNull($log->old_value);
        $this->assertSame(400000, json_decode($log->new_value, true)['amount']);
    }

    public function test_toggle_writes_a_toggle_row(): void
    {
        $this->manager()->setActive($this->superAdmin(), SubscriptionPlan::where('code', 'yearly')->firstOrFail(), false, self::REASON);

        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_PLAN_TOGGLE, $log->action);
        $this->assertTrue(json_decode($log->old_value, true)['is_active']);
        $this->assertFalse(json_decode($log->new_value, true)['is_active']);
        $this->assertFalse(SubscriptionPlan::where('code', 'yearly')->value('is_active'));
    }

    public function test_a_submit_that_changes_nothing_writes_nothing(): void
    {
        $this->assertFalse($this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(), self::REASON));
        $this->assertFalse($this->manager()->setActive($this->superAdmin(), $this->monthly(), true, self::REASON));

        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_when_the_audit_row_cannot_be_written_the_change_is_rolled_back(): void
    {
        DB::statement('DROP TABLE billing_audit_logs');

        foreach ([
            fn () => $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 123456]), self::REASON),
            fn () => $this->manager()->setActive($this->superAdmin(), SubscriptionPlan::where('code', 'yearly')->firstOrFail(), false, self::REASON),
            fn () => $this->manager()->create($this->superAdmin(), ['code' => 'ghost'] + $this->values(), self::REASON),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A failing audit write must abort the change.');
            } catch (\Illuminate\Database\QueryException) {
                // expected
            }
        }

        $this->assertSame(150000, $this->monthly()->amount);
        $this->assertTrue(SubscriptionPlan::where('code', 'yearly')->value('is_active'));
        $this->assertSame(0, SubscriptionPlan::where('code', 'ghost')->count());
    }

    // ----------------------------------------------------------- reason

    /** Reversed on request: the reason is optional everywhere, like the other admin forms. */
    public function test_the_reason_is_optional_and_a_blank_one_is_stored_as_null_while_the_audit_row_is_still_written(): void
    {
        $admin = $this->superAdmin();

        foreach ([null, '', '   ', "\n\t "] as $i => $reason) {
            $this->manager()->update($admin, $this->monthly(), $this->values(['amount' => 160000 + $i * 1000]), $reason);
        }
        $this->manager()->update($admin, $this->monthly(), $this->values(['amount' => 170000]), 'singkat');
        $this->manager()->setActive($admin, SubscriptionPlan::where('code', 'yearly')->firstOrFail(), false);
        $this->manager()->create($admin, ['code' => 'tanpa-alasan'] + $this->values());

        $logs = BillingAuditLog::orderBy('id')->get();
        $this->assertCount(7, $logs, 'every change is audited with or without a reason');
        $this->assertSame([null, null, null, null, 'singkat', null, null], $logs->pluck('reason')->all());
        $this->assertSame(160000, json_decode($logs[0]->new_value, true)['amount']);
        $this->assertSame($admin->id, $logs[0]->actor_user_id);
    }

    public function test_a_reason_over_500_characters_is_refused_and_nothing_changes(): void
    {
        foreach ([str_repeat('x', 501), '  '.str_repeat('x', 501)] as $reason) {
            try {
                $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 200000]), $reason);
                $this->fail('Must be refused.');
            } catch (PlanChangeException) {
                // expected
            }
        }

        $this->assertSame(150000, $this->monthly()->amount);
        $this->assertSame(0, BillingAuditLog::count());

        // Exactly 500 (after trimming) is fine.
        $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 200000]), ' '.str_repeat('x', 500).' ');
        $this->assertSame(500, mb_strlen(BillingAuditLog::sole()->reason));
    }

    // ------------------------------------------------------- validation

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidValues(): array
    {
        return [
            'amount below the minimum' => [['amount' => 999], 'amount'],
            'amount zero' => [['amount' => 0], 'amount'],
            'amount negative' => [['amount' => -5000], 'amount'],
            'amount with decimals' => [['amount' => '1500.50'], 'amount'],
            'amount not a number' => [['amount' => 'abc'], 'amount'],
            'amount above the typo guard' => [['amount' => 1_000_000_001], 'amount'],
            'duration zero' => [['duration_days' => 0], 'duration_days'],
            'duration above 3660' => [['duration_days' => 3661], 'duration_days'],
            'duration fractional' => [['duration_days' => '30.5'], 'duration_days'],
            'empty name' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('a', 61)], 'name'],
            'negative sort order' => [['sort_order' => -1], 'sort_order'],
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    public function test_invalid_values_are_refused(array $override, string $field): void
    {
        try {
            $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values($override), self::REASON);
            $this->fail('Must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(150000, $this->monthly()->amount);
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_boundary_values_are_accepted(): void
    {
        $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 1000, 'duration_days' => 3660, 'sort_order' => 0]), self::REASON);

        $this->assertSame([1000, 3660], [$this->monthly()->amount, $this->monthly()->duration_days]);
    }

    /**
     * @dataProvider invalidCodes
     */
    public function test_an_invalid_or_duplicate_code_is_refused(string $code): void
    {
        try {
            $this->manager()->create($this->superAdmin(), ['code' => $code] + $this->values(), self::REASON);
            $this->fail('Code ['.$code.'] must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertSame(2, SubscriptionPlan::count());
    }

    /** @return array<string, array{0: string}> */
    public static function invalidCodes(): array
    {
        return [
            'duplicate' => ['monthly'],
            'upper case' => ['Quarterly'],
            'space' => ['half year'],
            'slash' => ['a/b'],
            'leading dash' => ['-x'],
            'too long' => [str_repeat('a', 33)],
            'empty' => [''],
            'unicode' => ['paket-é'],
        ];
    }

    public function test_the_code_is_not_part_of_an_update(): void
    {
        $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 160000]) + ['code' => 'hijacked'], self::REASON);

        $this->assertSame(1, SubscriptionPlan::where('code', 'monthly')->count());
        $this->assertSame(0, SubscriptionPlan::where('code', 'hijacked')->count());
    }

    // ------------------------------------------------ last active plan

    public function test_the_last_active_plan_cannot_be_deactivated_while_checkout_is_enabled(): void
    {
        config(['subscriptions.checkout_enabled' => true]);
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);

        try {
            $this->manager()->setActive($this->superAdmin(), $this->monthly(), false, self::REASON);
            $this->fail('Must be refused.');
        } catch (PlanChangeException $e) {
            $this->assertStringContainsString('paket aktif terakhir', $e->getMessage());
        }

        // The same refusal applies when the edit form unticks "active".
        $this->assertTrue($this->raises(fn () => $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['is_active' => false]), self::REASON)));

        $this->assertTrue($this->monthly()->is_active);
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_deactivating_one_of_two_active_plans_is_fine(): void
    {
        config(['subscriptions.checkout_enabled' => true]);

        $this->assertTrue($this->manager()->setActive($this->superAdmin(), $this->monthly(), false, self::REASON));
    }

    public function test_the_last_active_plan_may_be_deactivated_when_checkout_is_off(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);

        $this->assertTrue($this->manager()->setActive($this->superAdmin(), $this->monthly(), false, self::REASON));
    }

    public function test_an_inactive_plan_can_be_reactivated_even_when_none_is_active(): void
    {
        config(['subscriptions.checkout_enabled' => true]);
        SubscriptionPlan::query()->update(['is_active' => false]);

        $this->assertTrue($this->manager()->setActive($this->superAdmin(), $this->monthly(), true, self::REASON));
    }

    // ------------------------------------------------------ authorization

    public function test_a_non_super_admin_actor_is_refused_by_the_service_itself(): void
    {
        try {
            $this->manager()->update(User::factory()->create(), $this->monthly(), $this->values(['amount' => 200000]), self::REASON);
            $this->fail('Must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(150000, $this->monthly()->amount);
    }

    public function test_plan_audit_rows_are_kept_90_days_then_pruned(): void
    {
        $this->manager()->update($this->superAdmin(), $this->monthly(), $this->values(['amount' => 160000]), self::REASON);
        $log = BillingAuditLog::sole();

        $log->forceFill(['created_at' => now()->subDays(89)])->save();
        $this->assertSame(0, (new BillingAuditLog)->prunable()->count());

        $log->forceFill(['created_at' => now()->subDays(91)])->save();
        $this->assertSame(1, (new BillingAuditLog)->prunable()->count());
    }

    public function test_every_plan_action_is_kept_90_days_while_sync_stays_a_year(): void
    {
        foreach ([BillingAuditLog::ACTION_PLAN_CREATE, BillingAuditLog::ACTION_PLAN_UPDATE, BillingAuditLog::ACTION_PLAN_TOGGLE, BillingAuditLog::ACTION_CHECK_STATUS] as $action) {
            $this->assertSame(90, BillingAuditLog::RETENTION_DAYS[$action], $action);
        }

        $this->assertSame(365, BillingAuditLog::RETENTION_DAYS[BillingAuditLog::ACTION_SYNC]);
    }

    public function test_no_code_path_deletes_a_plan(): void
    {
        foreach (['app/Services/PlanManager.php', 'app/Filament/Resources/SubscriptionPlanResource.php'] as $file) {
            $source = file_get_contents(base_path($file));

            foreach (['->delete(', '->forceDelete(', '::destroy(', 'SubscriptionPlan::truncate'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    private function raises(callable $call): bool
    {
        try {
            $call();
        } catch (PlanChangeException) {
            return true;
        }

        return false;
    }
}

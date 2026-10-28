<?php

namespace Tests\Feature\Billing;

use App\Filament\Resources\SubscriptionPlanResource;
use App\Filament\Resources\SubscriptionPlanResource\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlanResource\Pages\EditSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlanResource\Pages\ListSubscriptionPlans;
use App\Models\BillingAuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Super Admin "Paket" as a Filament resource (list, create and edit pages, no
 * modals for CRUD). Access is SubscriptionPlanPolicy (isSuperAdmin()); every
 * write goes through PlanManager, which audits it in the same transaction.
 */
class SubscriptionPlanResourceTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Penyesuaian harga sesuai keputusan tim bisnis';

    protected function setUp(): void
    {
        parent::setUp();

        config(['system.security.require_2fa_for_super_admin' => false]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::firstOrCreate(['name' => 'Super Admin'])]);

        return $user->fresh();
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

    private function edit(?User $as = null): \Livewire\Testing\TestableLivewire
    {
        return Livewire::actingAs($as ?? $this->superAdmin())->test(EditSubscriptionPlan::class, ['record' => $this->monthly()->getKey()]);
    }

    // ----------------------------------------------------------- access

    public function test_super_admin_opens_list_create_and_edit_and_the_list_shows_the_plans(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(SubscriptionPlanResource::getUrl('index'))->assertOk();
        $this->get(SubscriptionPlanResource::getUrl('create'))->assertOk();
        $this->get(SubscriptionPlanResource::getUrl('edit', ['record' => $this->monthly()]))->assertOk();

        Livewire::test(ListSubscriptionPlans::class)->assertSuccessful()
            ->assertSee('monthly')->assertSee('Rp150.000')->assertSee('Rp1.500.000')->assertSee('30 hari')->assertSee('Aktif');
    }

    public function test_every_organization_role_and_plain_users_are_forbidden_on_routes_and_components(): void
    {
        $organization = Organization::factory()->create();

        foreach (['owner', 'admin', 'member', null] as $role) {
            $user = User::factory()->create();

            if ($role !== null) {
                $organization->users()->attach($user->id, ['role' => $role]);
            }

            $this->actingAs($user);
            $this->get(SubscriptionPlanResource::getUrl('index'))->assertForbidden();
            $this->get(SubscriptionPlanResource::getUrl('create'))->assertForbidden();
            $this->get(SubscriptionPlanResource::getUrl('edit', ['record' => $this->monthly()]))->assertForbidden();

            Livewire::test(ListSubscriptionPlans::class)->assertForbidden();
            Livewire::test(CreateSubscriptionPlan::class)->assertForbidden();
            Livewire::test(EditSubscriptionPlan::class, ['record' => $this->monthly()->getKey()])->assertForbidden();
        }
    }

    /**
     * Filament's registerNavigationItems() skips a resource whose canViewAny() is false,
     * and canViewAny() is the policy: so the menu entry follows isSuperAdmin() exactly.
     */
    public function test_navigation_follows_the_policy_and_sits_in_the_platform_group(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);
        $this->assertFalse(SubscriptionPlanResource::canViewAny());

        $this->actingAs($this->superAdmin());
        $this->assertTrue(SubscriptionPlanResource::canViewAny());

        $this->assertSame('Platform', (new \ReflectionMethod(SubscriptionPlanResource::class, 'getNavigationGroup'))->invoke(null));
        $this->assertSame('Paket', (new \ReflectionMethod(SubscriptionPlanResource::class, 'getNavigationLabel'))->invoke(null));
        $this->assertSame('platform/plans', SubscriptionPlanResource::getSlug());
    }

    public function test_writes_are_refused_when_the_actor_stops_being_a_super_admin_mid_session(): void
    {
        $component = $this->edit();
        $this->actingAs(User::factory()->create());

        $component->fillForm($this->values(['amount' => 200000]))->call('save')->assertForbidden();

        $this->assertSame(150000, $this->monthly()->amount);
        $this->assertSame(0, BillingAuditLog::count());

        $list = Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class);
        $this->actingAs(User::factory()->create());
        $list->call('mountTableAction', 'toggle', (string) SubscriptionPlan::where('code', 'yearly')->value('id'))->call('callMountedTableAction');

        $this->assertTrue(SubscriptionPlan::where('code', 'yearly')->value('is_active'));
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_policy_allows_super_admin_and_denies_every_destructive_ability_even_to_them(): void
    {
        $admin = $this->superAdmin();
        $plan = $this->monthly();

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($admin->can($ability, SubscriptionPlan::class), $ability);
        }
        foreach (['view', 'update'] as $ability) {
            $this->assertTrue($admin->can($ability, $plan), $ability);
        }
        foreach (['delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse($admin->can($ability, $plan), $ability);
        }
        foreach (['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
            $this->assertFalse($admin->can($ability, SubscriptionPlan::class), $ability);
        }
    }

    // ----------------------------------------------------------- edit

    public function test_editing_a_plan_saves_it_and_audits_old_to_new_with_the_reason(): void
    {
        $this->edit()
            ->fillForm($this->values(['amount' => 175000, 'duration_days' => 31, 'reason' => self::REASON]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([175000, 31], [$this->monthly()->amount, $this->monthly()->duration_days]);
        $log = BillingAuditLog::sole();
        $this->assertSame('plan:monthly', $log->subject);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame(150000, json_decode($log->old_value, true)['amount']);
        $this->assertSame(175000, json_decode($log->new_value, true)['amount']);
    }

    public function test_the_reason_is_optional_blank_is_stored_as_null_and_over_500_is_refused(): void
    {
        foreach ([null, '', '          '] as $i => $reason) {
            $this->edit()->fillForm($this->values(['amount' => 160000 + $i, 'reason' => $reason]))->call('save')->assertHasNoFormErrors();
        }

        $this->assertSame([null, null, null], BillingAuditLog::orderBy('id')->pluck('reason')->all());

        $this->edit()->fillForm($this->values(['amount' => 170000, 'reason' => str_repeat('x', 501)]))->call('save')->assertHasFormErrors(['reason']);
        $this->assertSame(160002, $this->monthly()->amount);
    }

    public function test_the_edit_form_is_prefilled_the_code_is_read_only_and_never_changes(): void
    {
        $component = $this->edit()
            ->assertFormSet(['code' => 'monthly', 'name' => 'Monthly', 'amount' => 150000, 'duration_days' => 30, 'is_active' => true])
            ->assertFormFieldIsDisabled('code');

        $component->fillForm($this->values(['amount' => 161000]) + ['code' => 'hijacked'])->call('save');

        $this->assertSame(1, SubscriptionPlan::where('code', 'monthly')->count());
        $this->assertSame(0, SubscriptionPlan::where('code', 'hijacked')->count());
        $this->assertSame(161000, $this->monthly()->amount);
    }

    public function test_the_edit_form_validates_amount_duration_name_with_field_errors(): void
    {
        foreach ([
            ['amount', 999], ['amount', 'abc'], ['amount', 1_000_000_001], ['duration_days', 0], ['duration_days', 3661], ['name', ''], ['name', str_repeat('a', 61)], ['sort_order', -1],
        ] as [$field, $bad]) {
            $this->edit()->fillForm([$field => $bad] + $this->values())->call('save')->assertHasFormErrors([$field]);
        }

        $this->assertSame(150000, $this->monthly()->amount);
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_edit_page_shows_the_live_old_to_new_summary_and_the_new_checkouts_notice(): void
    {
        $html = $this->edit()->fillForm($this->values(['amount' => 199000]))->lastRenderedDom;

        $this->assertStringContainsString('Rp150.000', $html);
        $this->assertStringContainsString('Rp199.000', $html);
        $this->assertStringContainsString('Perubahan hanya berlaku untuk checkout BARU', $html);
        $this->assertStringContainsString('Alasan (opsional)', $html);
        $this->assertStringNotContainsString('Alasan *', $html);
    }

    public function test_when_the_audit_cannot_be_written_nothing_changes(): void
    {
        DB::statement('DROP TABLE billing_audit_logs');

        try {
            $this->edit()->fillForm($this->values(['amount' => 170000]))->call('save');
        } catch (\Throwable) {
            // an unexpected failure may surface; what matters is below
        }

        $this->assertSame(150000, $this->monthly()->amount);
    }

    // ----------------------------------------------------------- create

    public function test_creating_a_plan_works_audits_it_and_refuses_duplicate_or_malformed_codes(): void
    {
        $data = ['name' => 'Quarterly', 'amount' => 400000, 'duration_days' => 90, 'is_active' => true, 'sort_order' => 3, 'reason' => self::REASON];

        Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)
            ->fillForm(['code' => 'quarterly'] + $data)->call('create')->assertHasNoFormErrors();

        $this->assertSame(90, SubscriptionPlan::where('code', 'quarterly')->value('duration_days'));
        $log = BillingAuditLog::sole();
        $this->assertSame(BillingAuditLog::ACTION_PLAN_CREATE, $log->action);
        $this->assertNull($log->old_value);

        foreach (['monthly', 'Bad Code', '', 'a/b', str_repeat('a', 33)] as $code) {
            Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)
                ->fillForm(['code' => $code] + $data)->call('create')->assertHasFormErrors(['code']);
        }

        $this->assertSame(3, SubscriptionPlan::count());
    }

    public function test_create_without_a_reason_is_accepted(): void
    {
        Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)
            ->fillForm(['code' => 'promo', 'name' => 'Promo', 'amount' => 5000, 'duration_days' => 7, 'is_active' => true, 'sort_order' => 0])
            ->call('create')->assertHasNoFormErrors();

        $this->assertNull(BillingAuditLog::sole()->reason);
    }

    public function test_the_create_form_defaults_the_order_to_the_next_free_number(): void
    {
        Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)->assertFormSet(['sort_order' => 3]);

        SubscriptionPlan::where('code', 'yearly')->update(['sort_order' => 7]);
        Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)->assertFormSet(['sort_order' => 8]);

        SubscriptionPlan::query()->delete();
        Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class)->assertFormSet(['sort_order' => 1]);
    }

    public function test_the_price_field_shows_rp_with_dot_thousands_but_keeps_plain_digits_as_state(): void
    {
        $component = Livewire::actingAs($this->superAdmin())->test(CreateSubscriptionPlan::class);
        $field = $component->instance()->form->getComponent('data.amount');
        // The configuration is a JS object literal (not strict JSON), so assert on the text.
        $mask = $field->getMask()->toJson();

        $this->assertStringContainsString('"mask":"Rp. money"', $mask, 'prefix "Rp. " before the number');
        $this->assertStringContainsString('"thousandsSeparator":"."', $mask);
        $this->assertStringContainsString('"scale":0', $mask, 'whole rupiah, no decimals');

        // The mask is display only: what is submitted and saved is the bare number.
        $this->edit()->fillForm($this->values(['amount' => '210000']))->call('save')->assertHasNoFormErrors();
        $this->assertSame(210000, $this->monthly()->amount);
    }

    // ----------------------------------------------------------- toggle

    public function test_the_row_toggle_deactivates_and_reactivates_through_plan_manager_with_audit(): void
    {
        $yearly = SubscriptionPlan::where('code', 'yearly')->firstOrFail();
        $list = Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class);

        $list->callTableAction('toggle', $yearly);
        $this->assertFalse($yearly->fresh()->is_active);

        $list->callTableAction('toggle', $yearly);
        $this->assertTrue($yearly->fresh()->is_active);

        $logs = BillingAuditLog::orderBy('id')->get();
        $this->assertSame([BillingAuditLog::ACTION_PLAN_TOGGLE, BillingAuditLog::ACTION_PLAN_TOGGLE], $logs->pluck('action')->all());
        $this->assertTrue(json_decode($logs[0]->old_value, true)['is_active']);
        $this->assertFalse(json_decode($logs[0]->new_value, true)['is_active']);
    }

    public function test_the_last_active_plan_cannot_be_deactivated_while_checkout_is_on(): void
    {
        config(['subscriptions.checkout_enabled' => true]);
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);

        Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class)
            ->callTableAction('toggle', $this->monthly())
            ->assertNotified(__('Plan not changed'));

        $this->assertTrue($this->monthly()->is_active);
        $this->assertSame(0, BillingAuditLog::count());

        // The edit form refuses the same thing without writing anything.
        $this->edit()->fillForm($this->values(['is_active' => false]))->call('save');
        $this->assertTrue($this->monthly()->is_active);
        $this->assertSame(0, BillingAuditLog::count());
    }

    // ----------------------------------------------------------- no delete

    public function test_there_is_no_delete_or_bulk_action_anywhere(): void
    {
        $list = Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class);
        $html = $list->lastRenderedDom;

        $this->assertStringNotContainsString('Hapus', $html);
        $this->assertStringNotContainsString('Delete', $html);
        $this->assertSame([], $list->instance()->getCachedTableBulkActions());

        $this->edit()->assertDontSee('Hapus');

        foreach (['SubscriptionPlanResource.php', 'SubscriptionPlanResource/Pages/ListSubscriptionPlans.php', 'SubscriptionPlanResource/Pages/CreateSubscriptionPlan.php', 'SubscriptionPlanResource/Pages/EditSubscriptionPlan.php'] as $file) {
            $source = file_get_contents(app_path('Filament/Resources/'.$file));

            foreach (['->delete(', '->forceDelete(', 'DeleteAction', 'DeleteBulkAction', 'RestoreAction', '::destroy('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    public function test_the_resource_never_writes_a_plan_itself(): void
    {
        foreach (['SubscriptionPlanResource.php', 'SubscriptionPlanResource/Pages/CreateSubscriptionPlan.php', 'SubscriptionPlanResource/Pages/EditSubscriptionPlan.php'] as $file) {
            $source = file_get_contents(app_path('Filament/Resources/'.$file));

            foreach (['$record->update(', '$record->save(', '$plan->update(', '$plan->save(', 'SubscriptionPlan::create', 'SubscriptionPlan::query()->update', 'fill('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    // ----------------------------------------------------------- list

    public function test_the_list_filters_by_status_and_is_sorted_by_order(): void
    {
        SubscriptionPlan::where('code', 'yearly')->update(['is_active' => false]);

        Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class)
            ->filterTable('is_active', true)->assertSee('monthly')->assertDontSee('yearly')
            ->filterTable('is_active', false)->assertSee('yearly')->assertDontSee('monthly');

        SubscriptionPlan::where('code', 'monthly')->update(['sort_order' => 99]);
        $codes = Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class)->instance()->getTableRecords()->pluck('code')->all();
        $this->assertSame(['yearly', 'monthly'], $codes);
    }

    public function test_the_list_query_count_does_not_grow_with_the_number_of_plans(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class);

            return count(DB::getQueryLog());
        };

        $count();
        $before = $count();
        SubscriptionPlan::factory()->count(10)->create();
        $this->assertSame($before, $count());
    }

    public function test_xss_in_a_plan_name_is_escaped_in_the_list_and_the_edit_form(): void
    {
        $this->monthly()->update(['name' => '<img src=x onerror=alert(1)>']);

        $list = Livewire::actingAs($this->superAdmin())->test(ListSubscriptionPlans::class)->lastRenderedDom;
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $list);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $list);

        $edit = $this->edit()->fillForm($this->values(['name' => '<b>x</b>']))->lastRenderedDom;
        $this->assertStringNotContainsString('<b>x</b>', $edit);
    }

    // ----------------------------------------------------------- history

    public function test_the_edit_page_shows_this_plans_history_escaped_with_a_constant_number_of_queries(): void
    {
        $admin = $this->superAdmin();
        $manager = app(PlanManager::class);
        $manager->update($admin, SubscriptionPlan::where('code', 'yearly')->firstOrFail(), ['name' => 'Yearly', 'amount' => 1600000, 'duration_days' => 365, 'is_active' => true, 'sort_order' => 2], self::REASON);

        for ($i = 1; $i <= 3; $i++) {
            $manager->update($admin, $this->monthly(), ['name' => '<script>alert(1)</script>', 'amount' => 150000 + $i * 1000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 1], '<b>alasan</b> nomor '.$i);
        }

        $queries = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($admin)->test(EditSubscriptionPlan::class, ['record' => $this->monthly()->getKey()]);

            return count(DB::getQueryLog());
        };

        $queries();
        $withThree = $queries();
        $html = Livewire::actingAs($admin)->test(EditSubscriptionPlan::class, ['record' => $this->monthly()->getKey()])->lastRenderedDom;

        $this->assertStringContainsString('Riwayat paket', $html);
        $this->assertStringContainsString('Rp153.000', $html);
        $this->assertStringContainsString('&lt;b&gt;alasan&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>alasan</b>', $html);
        $this->assertStringNotContainsString('Rp1.500.000 -&gt; Rp1.600.000', $html, 'another plan\'s change must not leak in');

        for ($i = 4; $i <= 12; $i++) {
            $manager->update($admin, $this->monthly(), ['name' => 'Monthly', 'amount' => 150000 + $i * 1000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 1], 'nomor '.$i);
        }

        $this->assertSame($withThree, $queries(), 'history cost must not grow with the number of rows');
    }

    public function test_an_empty_history_says_so(): void
    {
        $this->assertStringContainsString('Belum ada perubahan tercatat untuk paket ini', $this->edit()->lastRenderedDom);
    }

    public function test_public_livewire_properties_hold_no_secrets_or_unrelated_records(): void
    {
        $page = $this->edit()->instance();

        foreach ((new \ReflectionClass($page))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($page);

            if (is_object($value)) {
                $this->assertInstanceOf(SubscriptionPlan::class, $value, $property->getName());
            }
        }

        $this->assertStringNotContainsString('doku', strtolower(json_encode($page->getPublicPropertiesDefinedBySubClass())));
    }
}

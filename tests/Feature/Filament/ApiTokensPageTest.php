<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ApiTokens;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The panel side of API tokens. This page is where a first token comes from —
 * the API refuses to mint one for a request that is itself token-authenticated
 * — so it must work for any panel user while never reaching past their own
 * tokens.
 */
class ApiTokensPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function panelUser(array $permissions = []): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Role '.(Role::count() + 1)]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_page_opens_for_a_user_holding_no_permissions(): void
    {
        $this->actingAs($this->panelUser());

        Livewire::test(ApiTokens::class)->assertSuccessful();
    }

    public function test_it_lists_only_the_users_own_tokens(): void
    {
        $user = $this->panelUser();
        $other = $this->panelUser();
        $user->createToken('mine');
        $other->createToken('theirs');

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->assertCanSeeTableRecords($user->tokens()->get())
            ->assertCanNotSeeTableRecords($other->tokens()->get());
    }

    public function test_creating_a_token_shows_the_secret_once(): void
    {
        $user = $this->panelUser();
        $this->actingAs($user);

        $page = Livewire::test(ApiTokens::class)
            ->callPageAction('create', ['name' => 'laptop', 'expires_at' => null])
            ->assertHasNoPageActionErrors();

        $token = $user->tokens()->firstOrFail();
        $this->assertSame('laptop', $token->name);
        $this->assertNotNull($token->expires_at, 'the panel must stamp an expiry too');

        // Shown in full, and it is the real secret: Sanctum stores
        // "{id}|{secret}" hashed, so the id prefix must match the new row.
        $plainText = $page->get('plainTextToken');
        $this->assertNotNull($plainText);
        $this->assertStringStartsWith($token->id.'|', $plainText);
        $page->assertSee($plainText);
    }

    public function test_a_token_name_is_required(): void
    {
        $this->actingAs($this->panelUser());

        Livewire::test(ApiTokens::class)
            ->callPageAction('create', ['name' => ''])
            ->assertHasPageActionErrors(['name']);
    }

    public function test_a_user_can_revoke_their_own_token(): void
    {
        $user = $this->panelUser();
        $user->createToken('ci');
        $token = $user->tokens()->firstOrFail();

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)->callTableAction('revoke', $token);

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * The row action is resolved against the page's own query, so a crafted
     * record key cannot reach another account's token.
     */
    public function test_a_user_cannot_revoke_someone_elses_token(): void
    {
        $user = $this->panelUser();
        $other = $this->panelUser();
        $other->createToken('theirs');
        $victim = $other->tokens()->firstOrFail();

        $this->actingAs($user);

        // Driven through the raw Livewire calls rather than callTableAction(),
        // which asserts the confirmation modal opened — here it must not.
        Livewire::test(ApiTokens::class)
            ->call('mountTableAction', 'revoke', (string) $victim->getKey())
            ->call('callMountedTableAction');

        $this->assertSame(1, $other->tokens()->count());
    }
}

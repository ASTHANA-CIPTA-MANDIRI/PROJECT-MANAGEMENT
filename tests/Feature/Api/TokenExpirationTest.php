<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sanctum tokens must expire so a leaked token cannot be used forever.
 * These exercise a real Bearer token end to end (not Sanctum::actingAs).
 */
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_default_token_lifetime_is_bounded(): void
    {
        $this->assertNotNull(config('sanctum.expiration'), 'API tokens must expire by default');
        $this->assertGreaterThan(0, config('sanctum.expiration'));
    }

    public function test_a_fresh_token_is_accepted(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = $this->userWith(['List projects']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/projects')->assertOk();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = $this->userWith(['List projects']);
        $token = $user->createToken('test')->plainTextToken;

        // Age the token past the 60-minute window.
        $user->tokens()->update(['created_at' => now()->subMinutes(120)]);

        $this->withToken($token)->getJson('/api/v1/projects')->assertUnauthorized();
    }
}

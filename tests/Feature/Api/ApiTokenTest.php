<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Self-service token endpoints: a user must be able to issue, review and
 * revoke their own API credentials without a shell — and must not be able to
 * touch anyone else's.
 *
 * These use real bearer tokens (not Sanctum::actingAs) wherever the difference
 * between session and token authentication is the thing under test.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // ------------------------------------------------------ authentication

    public function test_the_token_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/tokens')->assertUnauthorized();
        $this->postJson('/api/v1/tokens', ['name' => 'ci'])->assertUnauthorized();
        $this->deleteJson('/api/v1/tokens/1')->assertUnauthorized();
    }

    /**
     * No permission gates these: a token can never do more than the user it
     * belongs to, so a user holding no permissions at all still manages their
     * own credentials.
     */
    public function test_a_user_without_any_permission_can_manage_their_own_tokens(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci'])
            ->assertCreated();
    }

    // ------------------------------------------------------------- issuing

    public function test_creating_a_token_returns_the_secret_exactly_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ci')
            ->assertJsonStructure(['data' => ['id', 'name', 'expires_at', 'created_at'], 'plain_text_token']);

        $plainText = $response->json('plain_text_token');
        $this->assertNotEmpty($plainText);

        // The secret really works...
        $this->withToken($plainText)->getJson('/api/v1/tokens')->assertOk();

        // ...and is nowhere to be found afterwards.
        $this->withToken($plainText)
            ->getJson('/api/v1/tokens')
            ->assertOk()
            ->assertJsonMissing(['plain_text_token' => $plainText]);
    }

    public function test_a_token_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tokens', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_expiry_in_the_past_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci', 'expires_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    /**
     * The stored expiry is the honest answer to "when does this stop working",
     * so it is stamped rather than left to the global window alone.
     */
    public function test_a_new_token_expires_within_the_configured_window(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/tokens', ['name' => 'ci'])->assertCreated();

        $token = $user->tokens()->firstOrFail();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addMinutes(60)));
    }

    public function test_a_shorter_expiry_is_honoured(): void
    {
        config(['sanctum.expiration' => 20160]);
        $user = User::factory()->create();
        $wanted = now()->addHour();

        $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci', 'expires_at' => $wanted->toIso8601String()])
            ->assertCreated();

        $this->assertEqualsWithDelta(
            $wanted->timestamp,
            $user->tokens()->firstOrFail()->expires_at->timestamp,
            5
        );
    }

    public function test_a_longer_expiry_is_clamped_to_the_configured_window(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci', 'expires_at' => now()->addYear()->toIso8601String()])
            ->assertCreated();

        $this->assertTrue(
            $user->tokens()->firstOrFail()->expires_at->lessThanOrEqualTo(now()->addMinutes(60)),
            'a caller must not be able to ask for a token that outlives the global window'
        );
    }

    /**
     * The stamped `expires_at` is only a claim until something enforces it.
     * Sanctum's guard (vendor/laravel/sanctum Guard::supportsTokens()) checks
     * it on every request; this proves a token issued through this app's own
     * flow actually stops authenticating once that moment passes, not just
     * that the column holds the right date.
     */
    public function test_an_expired_token_cannot_be_used(): void
    {
        config(['sanctum.expiration' => 1]);
        $user = User::factory()->create();

        $plainText = $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'ci'])
            ->assertCreated()
            ->json('plain_text_token');

        $this->travelTo(now()->addMinutes(2));

        // Same guard-caching reason as the self-revoke test below: without
        // this the assertion would authenticate against a stale resolution
        // from the request above instead of re-checking expiry.
        $this->app['auth']->forgetGuards();
        $this->withToken($plainText)->getJson('/api/v1/tokens')->assertUnauthorized();
    }

    /**
     * Bounded expiry is what stops a leaked token living forever; a token that
     * can mint successors renews itself indefinitely and hands that back.
     */
    public function test_an_api_token_cannot_mint_another_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('first')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/tokens', ['name' => 'second'])
            ->assertForbidden();

        $this->assertSame(1, $user->tokens()->count());
    }

    // ------------------------------------------------------------- listing

    public function test_listing_shows_only_the_callers_own_tokens(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->createToken('mine');
        $other->createToken('theirs');

        $this->actingAs($user)
            ->getJson('/api/v1/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'mine');
    }

    public function test_listing_never_returns_the_stored_token_hash(): void
    {
        $user = User::factory()->create();
        $user->createToken('mine');
        $hash = $user->tokens()->firstOrFail()->token;

        $response = $this->actingAs($user)->getJson('/api/v1/tokens')->assertOk();

        $this->assertStringNotContainsString($hash, $response->getContent());
        $this->assertArrayNotHasKey('token', $response->json('data.0'));
    }

    // ------------------------------------------------------------ revoking

    public function test_a_user_can_revoke_their_own_token(): void
    {
        $user = User::factory()->create();
        $user->createToken('ci');
        $id = $user->tokens()->firstOrFail()->id;

        $this->actingAs($user)->deleteJson("/api/v1/tokens/{$id}")->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * The panic button for a leaked credential: the token being used may
     * revoke itself, even though it may not create anything.
     */
    public function test_a_token_can_revoke_itself(): void
    {
        $user = User::factory()->create();
        $plainText = $user->createToken('ci')->plainTextToken;
        $id = $user->tokens()->firstOrFail()->id;

        $this->withToken($plainText)->deleteJson("/api/v1/tokens/{$id}")->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());

        // The guard caches the user it resolved for the previous request, so
        // drop it: without this the assertion below would pass on a stale
        // identity instead of re-checking the (now deleted) token.
        $this->app['auth']->forgetGuards();
        $this->withToken($plainText)->getJson('/api/v1/tokens')->assertUnauthorized();
    }

    public function test_a_user_cannot_revoke_someone_elses_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->createToken('theirs');
        $victim = $other->tokens()->firstOrFail();

        // 404, not 403: an id from another account must not even be
        // confirmable as existing.
        $this->actingAs($user)->deleteJson("/api/v1/tokens/{$victim->id}")->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }
}

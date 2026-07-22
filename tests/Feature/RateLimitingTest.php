<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Rate limiter state lives in the cache; isolate each test.
        Cache::flush();
    }

    // --------------------------------------------------------- API: 100/token

    public function test_the_api_advertises_a_limit_of_100(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 100);
    }

    public function test_the_api_budget_is_separate_per_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('a')->plainTextToken;
        $tokenB = $user->createToken('b')->plainTextToken;

        // Each token, after a single request, should report the same remaining
        // budget (99) - proving the budgets are independent, not shared.
        $this->withToken($tokenA)->getJson('/api/user')
            ->assertOk()->assertHeader('X-RateLimit-Remaining', 99);

        // Reset the guard so the second request re-resolves the *new* token;
        // otherwise the test's persistent container caches the first user.
        $this->app['auth']->forgetGuards();

        $this->withToken($tokenB)->getJson('/api/user')
            ->assertOk()->assertHeader('X-RateLimit-Remaining', 99);
    }

    public function test_the_api_returns_429_when_the_token_budget_is_exhausted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        for ($i = 0; $i < 100; $i++) {
            $this->withToken($token)->getJson('/api/user')->assertOk();
        }

        $this->withToken($token)->getJson('/api/user')->assertStatus(429);
    }

    // --------------------------------------------------------- public: 60/IP

    public function test_public_endpoints_advertise_a_limit_of_60(): void
    {
        $ticket = Ticket::factory()->create();

        $this->get("/tickets/share/{$ticket->code}")
            ->assertHeader('X-RateLimit-Limit', 60);
    }

    public function test_public_endpoints_return_429_after_60_requests(): void
    {
        $ticket = Ticket::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->get("/tickets/share/{$ticket->code}")->assertRedirect();
        }

        $this->get("/tickets/share/{$ticket->code}")->assertStatus(429);
    }

    public function test_public_and_api_budgets_do_not_interfere(): void
    {
        $ticket = Ticket::factory()->create();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        // Spend some public budget...
        $this->get("/tickets/share/{$ticket->code}")
            ->assertHeader('X-RateLimit-Remaining', 59);

        // ...the API budget is still full.
        $this->withToken($token)->getJson('/api/user')
            ->assertHeader('X-RateLimit-Remaining', 99);
    }
}

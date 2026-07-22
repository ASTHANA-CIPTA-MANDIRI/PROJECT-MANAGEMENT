<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replaces the Laravel skeleton's ExampleTest, which asserted that "/" returns
 * 200. It never did: the panel redirects guests to the login screen.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests render real pages whose layout pulls in Vite assets.
        // CI does not run `npm run build`, so there is no manifest; stub the
        // Vite directive out so rendering does not depend on built assets.
        $this->withoutVite();
    }

    public function test_the_home_page_redirects_a_guest_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect();
    }

    public function test_the_login_page_is_publicly_reachable(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_the_login_page_does_not_offer_removed_providers(): void
    {
        $response = $this->get('/login');

        $response->assertDontSee('Facebook', false);
        $response->assertDontSee('Twitter', false);
        $response->assertDontSee('OIDC', false);
    }

    /**
     * The panel is mounted at the site root (filament.path is empty), so "/"
     * is the dashboard and must never render for a guest.
     */
    public function test_a_guest_is_sent_to_login_rather_than_the_dashboard(): void
    {
        $this->get('/')->assertRedirectContains('login');
    }
}

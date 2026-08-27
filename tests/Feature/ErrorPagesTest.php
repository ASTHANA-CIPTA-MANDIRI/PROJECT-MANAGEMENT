<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Custom error pages for 403, 404, 419 and 500, all sharing the same card
 * layout. APP_DEBUG=true in testing (see .env.testing) makes Laravel render
 * Whoops instead of these views for a real thrown exception, so - like
 * RegistrationWorkflowTest already does for 403 - each view is rendered
 * directly rather than through a route that throws.
 */
class ErrorPagesTest extends TestCase
{
    public function test_the_404_page_renders_without_leaking_exception_details(): void
    {
        $html = strtolower(view('errors.404')->render());

        $this->assertStringContainsString('404', $html);
        $this->assertStringContainsString(strtolower(__('Not found')), $html);
        $this->assertStringNotContainsString('stack trace', $html);
        $this->assertStringNotContainsString(strtolower(base_path()), $html);
    }

    public function test_the_419_page_explains_the_expired_session(): void
    {
        $html = strtolower(view('errors.419')->render());

        $this->assertStringContainsString('419', $html);
        $this->assertStringContainsString(strtolower(__('Your session has expired. Please reload the page and sign in again if needed.')), $html);
    }

    public function test_the_500_page_renders_without_leaking_exception_details(): void
    {
        $html = strtolower(view('errors.500')->render());

        $this->assertStringContainsString('500', $html);
        $this->assertStringNotContainsString('stack trace', $html);
        $this->assertStringNotContainsString(strtolower(base_path()), $html);
        $this->assertStringNotContainsString('exception', $html);
    }

    public function test_every_error_page_offers_a_way_back_home(): void
    {
        foreach (['403', '404', '419', '500'] as $code) {
            $html = strtolower(view("errors.{$code}")->render());

            $this->assertStringContainsString(strtolower(__('Back to home')), $html, "errors.{$code} is missing a way back home");
        }
    }
}

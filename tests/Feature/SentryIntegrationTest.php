<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Records events instead of sending them over the network.
     */
    private function recordingTransport(\ArrayObject $sink): TransportInterface
    {
        return new class($sink) implements TransportInterface
        {
            public function __construct(private \ArrayObject $sink) {}

            public function send(Event $event): Result
            {
                $this->sink->append($event);

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
    }

    // ------------------------------------------------------------ wiring

    public function test_sentry_is_registered(): void
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    public function test_configuration_is_env_driven(): void
    {
        // Disabled by default so dev/test never phone home. The DSN is empty
        // whether unset (null) or present-but-blank in .env (''); both mean off.
        $this->assertEmpty(config('sentry.dsn'));
        // Performance monitoring knob is present and env-driven.
        $this->assertArrayHasKey('traces_sample_rate', config('sentry'));
    }

    // ------------------------------------------------- exception capture

    public function test_the_handler_reports_exceptions_to_sentry_with_user_context(): void
    {
        $events = new \ArrayObject;

        $hub = SentrySdk::getCurrentHub();
        $originalClient = $hub->getClient();

        // Build a client with our recording transport and the Laravel
        // integration (so user context is attached like in production).
        $client = (new ClientBuilder(new Options([
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/0',
            'default_integrations' => false,
            'integrations' => [new Integration],
        ])))->setTransport($this->recordingTransport($events))->getClient();

        $hub->bindClient($client);

        try {
            $user = User::factory()->create();
            $this->actingAs($user);

            // Report through the real Laravel handler, which our reportable
            // callback forwards to Sentry.
            app(ExceptionHandler::class)->report(new \RuntimeException('boom for sentry'));

            $this->assertCount(1, $events, 'exception should reach Sentry');

            /** @var Event $event */
            $event = $events[0];
            $this->assertSame('boom for sentry', $event->getExceptions()[0]->getValue());
            $this->assertNotNull($event->getUser(), 'user context should be attached');
            $this->assertSame((string) $user->id, (string) $event->getUser()->getId());
        } finally {
            if ($originalClient) {
                $hub->bindClient($originalClient);
            }
        }
    }
}

<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: records execution in static state so a Google Pub/Sub round trip can assert the job ran.
 */
class GoogleE2ETestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public static bool $handled = false;
    public static ?string $handledMessage = null;

    public string $message;

    /**
     * Fixed topic so tests can easily assert routing.
     */
    public string $statelessTopic = 'stateless-queue-e2e-topic';

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        static::$handled = true;
        static::$handledMessage = $this->message;

        // For full HTTP round-trip tests we also drop a marker file under the
        // Laravel application's base path so we can observe the effect
        // across process boundaries.
        $path = function_exists('base_path')
            ? base_path('stateless_http_e2e.txt')
            : getcwd() . DIRECTORY_SEPARATOR . 'stateless_http_e2e.txt';

        @file_put_contents($path, $this->message);
    }
}

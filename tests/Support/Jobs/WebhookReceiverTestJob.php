<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: records execution and the received payload in static state.
 *
 * Lets webhook tests assert not just that the job ran, but that the payload survived the round trip.
 */
final class WebhookReceiverTestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public static bool $wasRun = false;
    public static ?string $receivedMessage = null;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void
    {
        static::$wasRun = true;
        static::$receivedMessage = $this->message;
    }
}


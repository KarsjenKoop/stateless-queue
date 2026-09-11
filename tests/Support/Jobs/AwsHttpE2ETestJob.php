<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: records execution in static state so an AWS SNS HTTP round trip can assert the job ran.
 *
 * Uses a non-promoted constructor parameter assigned to a property, matching the shape the payload
 * inference has to handle for older-style jobs.
 */
class AwsHttpE2ETestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public static bool $handled = false;
    public static ?string $handledMessage = null;

    public string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        static::$handled = true;
        static::$handledMessage = $this->message;
    }
}


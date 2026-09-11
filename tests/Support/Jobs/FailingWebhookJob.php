<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;
use RuntimeException;

/**
 * Fixture: throws from handle(), so webhook tests can assert a failing job produces a 500 response.
 *
 * `$handled` proves the job was actually reached rather than rejected earlier in the pipeline.
 */
final class FailingWebhookJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public static bool $handled = false;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void
    {
        self::$handled = true;

        throw new RuntimeException('Job failed for testing');
    }
}


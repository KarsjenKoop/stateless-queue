<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: minimal job used to assert what the NullAdapter writes to the log.
 */
final class NullAdapterTestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void {}
}


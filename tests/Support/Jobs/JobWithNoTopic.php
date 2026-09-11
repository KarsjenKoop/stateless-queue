<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: declares neither topic property, so topic resolution must fall through to config.
 */
final class JobWithNoTopic implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function handle(): void {}
}


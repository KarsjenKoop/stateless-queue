<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: declares `$statelessTopic` with no constructor, isolating topic resolution from payload inference.
 */
final class JobWithSpecificTopic implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $statelessTopic = 'special-topic';

    public function handle(): void {}
}


<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: declares no topic, so topic-routing tests can assert the config default is used.
 */
class DefaultTopicStatelessJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        // No-op: routing tests only care about topic and class name
    }
}


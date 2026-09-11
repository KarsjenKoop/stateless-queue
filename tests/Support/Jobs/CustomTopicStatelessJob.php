<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: declares `$statelessTopic`, so topic-routing tests can assert the property wins over config.
 */
class CustomTopicStatelessJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $message;

    public string $statelessTopic = 'stateless-queue-e2e-topic-custom';

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        // No-op: routing tests only care about topic and class name
    }
}


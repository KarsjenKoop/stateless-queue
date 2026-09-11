<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: declares the snake_case `$stateless_topic`, covering the second step of topic resolution.
 */
class SnakeCaseTopicStatelessJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $message;

    public string $stateless_topic = 'stateless-queue-e2e-topic-snake';

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        // No-op: routing tests only care about topic and class name
    }
}


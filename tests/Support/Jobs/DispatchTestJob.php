<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: exercises `dispatch()` / `push()` with a custom topic and a single scalar payload field.
 */
final class DispatchTestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $statelessTopic = 'custom-topic';

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void {}
}


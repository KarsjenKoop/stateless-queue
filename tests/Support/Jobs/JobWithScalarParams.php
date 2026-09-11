<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: the baseline happy path — two promoted scalar properties that infer cleanly.
 */
final class JobWithScalarParams implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $message,
        public int $count,
    ) {}

    public function handle(): void {}
}


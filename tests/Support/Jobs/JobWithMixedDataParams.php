<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: covers the full set of supported built-in payload types — string, array, and bool.
 */
final class JobWithMixedDataParams implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $id,
        public array $meta,
        public bool $flag,
    ) {}

    public function handle(): void {}
}


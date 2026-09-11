<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: has a defaulted parameter, so inference can be checked to include its current value.
 */
final class JobWithOptionalParam implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $required,
        public string $optional = 'default',
    ) {}

    public function handle(): void {}
}


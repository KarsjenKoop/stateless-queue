<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: has no constructor, so payload inference must return an empty array rather than reflecting.
 */
final class JobWithNoConstructor implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function handle(): void {}
}


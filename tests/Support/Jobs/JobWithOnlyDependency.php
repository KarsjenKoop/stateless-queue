<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: takes a class-typed constructor argument that is *not* promoted to a property.
 *
 * Payload inference must skip it silently — it is a dependency-injection argument, not payload data.
 */
final class JobWithOnlyDependency implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        \stdClass $service,
    ) {}

    public function handle(): void {}
}


<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: mixes a class-typed promoted property with a scalar one.
 *
 * Payload inference must reject this — a promoted class-typed property cannot cross the wire.
 */
final class JobWithDependency implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public \stdClass $logger,
        public string $message,
    ) {}

    public function handle(): void {}
}


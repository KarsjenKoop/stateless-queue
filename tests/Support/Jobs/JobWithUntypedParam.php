<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: has an untyped promoted property.
 *
 * Payload inference must reject this — without a type declaration it cannot prove the value is safe.
 */
final class JobWithUntypedParam implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public $untyped,
    ) {}

    public function handle(): void {}
}

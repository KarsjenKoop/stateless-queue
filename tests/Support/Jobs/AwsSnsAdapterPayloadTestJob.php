<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: minimal job used to inspect the payload the AwsSnsAdapter hands to the SNS client.
 */
final class AwsSnsAdapterPayloadTestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void {}
}


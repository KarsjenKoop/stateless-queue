<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: minimal job used to inspect the payload the GooglePubSubAdapter hands to the Pub/Sub client.
 */
final class GooglePubSubAdapterPayloadTestJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void {}
}


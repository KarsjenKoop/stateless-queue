<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Fixture: overrides getPayload() to return an invalid UTF-8 byte sequence.
 *
 * Forces the JSON encoding failure path in OutgoingJobMessage::toJson().
 */
class NonEncodablePayloadJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function getPayload(): array
    {
        return ['invalid' => "\x80\x81\x82"];
    }

    public function handle(): void
    {
    }
}
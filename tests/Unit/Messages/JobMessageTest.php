<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Messages;

use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Messages\IncomingJobMessage;
use Karsjen\StatelessQueue\Messages\JobMessage;
use PHPUnit\Framework\TestCase;

/**
 * The JobMessage envelope contract shared by both directions.
 *
 * Validates:
 * - `toArray()` emits the wire keys, including `version`, defaulting to `JobMessage::DEFAULT_VERSION`.
 * - `IncomingJobMessage` preserves an explicit `version` from the payload rather than overwriting it.
 * - Transport-only fields (`attributes`, `source`) stay out of the serialised envelope.
 *
 * Does not validate:
 * - JSON encoding failures (covered by the payload encoding tests).
 * - Provider envelope parsing (covered by the adapter tests).
 *
 * This is a plain PHPUnit test — the DTOs have no framework dependencies.
 */
class JobMessageTest extends TestCase
{
    public function test_outgoing_message_includes_default_version(): void
    {
        $msg = new OutgoingJobMessage(
            uuid: 'uuid-1',
            jobClass: 'JobClass',
            topic: 'topic-1',
            payload: ['foo' => 'bar'],
            timestamp: 123456789
        );

        $array = $msg->toArray();

        $this->assertArrayHasKey('version', $array);
        $this->assertSame(JobMessage::DEFAULT_VERSION, $array['version']);
    }

    public function test_incoming_message_extracts_version(): void
    {
        $data = [
            'uuid' => 'uuid-1',
            'job_class' => 'JobClass',
            'topic' => 'topic-1',
            'payload' => ['foo' => 'bar'],
            'timestamp' => 123456789,
            'version' => 2,
        ];

        $msg = IncomingJobMessage::fromArray($data);

        $this->assertSame(2, $msg->version);
    }

    public function test_incoming_message_defaults_version_if_missing(): void
    {
        $data = [
            'uuid' => 'uuid-1',
            'job_class' => 'JobClass',
            'topic' => 'topic-1',
            'payload' => ['foo' => 'bar'],
            'timestamp' => 123456789,
        ];

        $msg = IncomingJobMessage::fromArray($data);

        $this->assertSame(JobMessage::DEFAULT_VERSION, $msg->version);
    }
}

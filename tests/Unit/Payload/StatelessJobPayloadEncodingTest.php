<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Payload;

use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GoogleE2ETestJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\NonEncodablePayloadJob;
use Karsjen\StatelessQueue\Tests\TestCase;
use RuntimeException;

/**
 * Encoding and invariants for outgoing message payloads.
 *
 * Unit boundary:
 * - Inside: building `OutgoingJobMessage` data and enforcing JSON-encodable payload constraints.
 * - Outside: adapter publishing and broker envelopes.
 *
 * Why this matters:
 * - If we accidentally permit PHP-serialized objects or non-encodable payloads, stateless transport breaks
 *   (and consumers may be non-PHP).
 *
 * Validates:
 * - Payload contains only constructor argument data (JSON encodable array).
 * - Payload JSON does not contain PHP-serialized object markers.
 * - Non-JSON-encodable payloads throw a RuntimeException with a clear message.
 *
 * Does not validate:
 * - Adapter-specific push formatting (covered by adapter tests).
 * - Payload inference rules (covered by `JobPayloadInferenceTest`).
 */
class StatelessJobPayloadEncodingTest extends TestCase
{
    private function messageFromJob(object $job, string $topic): OutgoingJobMessage
    {
        config()->set('stateless-queue.default_topic', $topic);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('createOutgoingMessage');

        return $method->invoke($job);
    }

    /**
     * Verifies `OutgoingJobMessage` carries an array payload and preserves the core envelope metadata.
     *
     * Validates:
     * - `payload` is an array containing constructor data.
     * - `jobClass` is the job FQCN.
     * - `topic` is set to the provided topic argument.
     *
     * Out of scope:
     * - Broker-specific encoding (SNS / Pub/Sub).
     */
    public function test_payload_contains_json_of_constructor_arguments_only(): void
    {
        // Given
        $job = new GoogleE2ETestJob('Hello E2E');

        // When
        $payload = $this->messageFromJob($job, 'test-topic');

        // Then
        $this->assertIsArray($payload->payload);
        $this->assertArrayHasKey('message', $payload->payload);
        $this->assertSame('Hello E2E', $payload->payload['message']);
        $this->assertSame(GoogleE2ETestJob::class, $payload->jobClass);
        $this->assertSame('stateless-queue-e2e-topic', $payload->topic);
    }

    /**
     * Guards against PHP serialization artifacts leaking into the payload JSON.
     *
     * Validates:
     * - JSON encoding the payload does not contain typical PHP serialization markers (`O:`, `s:`).
     *
     * Out of scope:
     * - Whether the JSON is compact/pretty; only safety invariants are asserted.
     */
    public function test_payload_is_json_object_not_php_serialized(): void
    {
        // Given
        $job = new GoogleE2ETestJob('test');
        $payload = $this->messageFromJob($job, 'topic');

        // When
        $encoded = json_encode($payload->payload);

        // Then
        $this->assertNotFalse($encoded);
        $this->assertStringNotContainsString('O:', $encoded);
        $this->assertStringNotContainsString('s:', $encoded);
    }

    /**
     * Ensures non-JSON-encodable job payloads fail fast with a clear exception.
     *
     * Validates:
     * - Serialising the message via `OutgoingJobMessage::toJson()` throws `RuntimeException`.
     * - The exception message is stable and actionable.
     *
     * Out of scope:
     * - Any recovery strategy; this is intentionally fail-fast.
     */
    public function test_non_encodable_payload_throws_runtime_exception(): void
    {
        // Given
        $job = new NonEncodablePayloadJob();

        // Then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OutgoingJobMessage: payload is not JSON-encodable');

        // When
        $this->messageFromJob($job, 'test-topic')->toJson();
    }
}


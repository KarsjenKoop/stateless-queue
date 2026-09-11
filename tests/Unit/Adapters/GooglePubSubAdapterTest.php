<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Google\Cloud\PubSub\PubSubClient;
use Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter;
use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GooglePubSubAdapterPayloadTestJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Google Pub/Sub adapter error-wrapping behavior.
 *
 * Unit boundary:
 * - Inside: `GooglePubSubAdapter::publish()` exception translation (SDK/client throwables -> RuntimeException).
 * - Outside: Pub/Sub itself (real service or emulator) and Google SDK transport behavior (covered by E2E).
 *
 * Test strategy:
 * - Stub `PubSubClient` to return a fake topic whose `publish()` throws.
 * - Inject the stub into the adapter via reflection to avoid real IO.
 *
 * Validates:
 * - Low-level Pub/Sub client exceptions are wrapped into a RuntimeException with context.
 *
 * Does not validate:
 * - Successful publish behavior (covered by E2E tests against the emulator).
 */
class GooglePubSubAdapterTest extends TestCase
{
    /**
     * Ensures publish-time throwables are wrapped into a domain-level RuntimeException.
     *
     * Validates:
     * - A low-level exception thrown by the SDK/topic publish is caught and rethrown as a `RuntimeException`
     *   with a stable "StatelessQueue Google Pub/Sub error:" message prefix.
     * - The original exception is preserved as `$e->getPrevious()`.
     *
     * Out of scope:
     * - Topic naming, message attributes, and serialization (these are covered elsewhere).
     */
    public function test_push_wraps_exceptions_in_runtime_exception(): void
    {
        $adapter = new GooglePubSubAdapter(['project_id' => 'test-project', 'key_file' => __FILE__]);

        $topicMock = new class
        {
            public function publish(array $_config): void
            {
                throw new \Exception('low-level publish failure');
            }
        };

        $pubSubMock = $this->createStub(PubSubClient::class);
        $pubSubMock->method('topic')->willReturn($topicMock);

        // Inject the stub PubSub client (ReflectionProperty::setAccessible is a no-op on PHP 8.1+).
        $ref = new \ReflectionClass($adapter);
        $property = $ref->getProperty('pubSub');
        $property->setValue($adapter, $pubSubMock);

        $payload = new OutgoingJobMessage(
            uuid: 'test-uuid',
            jobClass: GooglePubSubAdapterPayloadTestJob::class,
            topic: 'test-topic',
            payload: ['message' => 'hello'],
            timestamp: time(),
        );

        try {
            $adapter->publish($payload);
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('google', $e->getMessage());
            $this->assertStringContainsString('low-level publish failure', $e->getMessage());
            $this->assertInstanceOf(\Exception::class, $e->getPrevious());
            $this->assertSame('low-level publish failure', $e->getPrevious()->getMessage());
        }
    }
}
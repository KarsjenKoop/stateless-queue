<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sns\SnsClient;
use Karsjen\StatelessQueue\Adapters\AwsSnsAdapter;
use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Tests\Support\Jobs\AwsSnsAdapterPayloadTestJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AWS SNS adapter error-wrapping behavior.
 *
 * Unit boundary:
 * - Inside: `AwsSnsAdapter::publish()` exception translation (AWS SDK -> library-owned RuntimeException).
 * - Outside: SNS itself and the AWS SDK’s transport behavior (covered by E2E tests and AWS SDK).
 *
 * Test strategy:
 * - Replace the internal SNS client with a stub that always throws an `AwsException` from `publish()`.
 * - This keeps the test hermetic (no credentials, no IO).
 *
 * Validates:
 * - Low-level AWS client exceptions are wrapped into a RuntimeException with context.
 *
 * Does not validate:
 * - Successful publish behavior (covered by E2E routing tests with LocalStack).
 */
class AwsSnsAdapterTest extends TestCase
{
    /**
     * Confirms AWS SDK exceptions are wrapped into a consistent RuntimeException contract.
     *
     * Validates:
     * - `AwsSnsAdapter::publish()` catches `AwsException` and throws a `RuntimeException` whose message
     *   starts with "StatelessQueue AWS error:".
     * - The original `AwsException` is preserved as `$e->getPrevious()` for debugging/root cause.
     *
     * Out of scope:
     * - Topic ARN resolution and request formatting.
     * - Any successful publish behavior.
     */
    public function test_push_wraps_aws_exception_in_runtime_exception(): void
    {
        $adapter = new AwsSnsAdapter([
            'region' => 'us-east-1',
            'account_id' => '000000000000',
        ]);

        $failingClient = new class extends SnsClient
        {
            public function __construct()
            {
                // Intentionally skip parent constructor; this client is only used as a stub.
            }

            public function publish(array $_args = []): Result
            {
                throw new AwsException('mock aws failure', new Command('Publish'));
            }
        };

        // Inject the stub SNS client (ReflectionProperty::setAccessible is a no-op on PHP 8.1+).
        $ref = new \ReflectionClass($adapter);
        $property = $ref->getProperty('sns');
        $property->setValue($adapter, $failingClient);

        $payload = new OutgoingJobMessage(
            uuid: 'test-uuid',
            jobClass: AwsSnsAdapterPayloadTestJob::class,
            topic: 'test-topic',
            payload: ['message' => 'hello'],
            timestamp: time(),
        );

        try {
            $adapter->publish($payload);
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('aws', $e->getMessage());
            $this->assertStringContainsString('mock aws failure', $e->getMessage());
            $this->assertInstanceOf(AwsException::class, $e->getPrevious());
            $this->assertSame('mock aws failure', $e->getPrevious()->getMessage());
        }
    }
}
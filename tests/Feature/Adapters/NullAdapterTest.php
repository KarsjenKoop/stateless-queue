<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Adapters;

use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Tests\Support\Jobs\NullAdapterTestJob;
use Karsjen\StatelessQueue\Tests\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Null adapter behavior (development/safe default).
 *
 * Context:
 * - The Null adapter exists as a safe fallback and as a dev-friendly adapter: it should never attempt IO.
 * - Instead, it logs a structured representation of the would-be published message.
 *
 * Validates:
 * - Null adapter logs the payload and does not throw when pushing a job.
 * - The payload is logged verbatim and unredacted, at `info` level — the documented hazard that makes
 *   this a development-only adapter.
 *
 * Does not validate:
 * - Transport behavior for real adapters (AWS/Google).
 */
class NullAdapterTest extends TestCase
{
    /**
     * Ensures pushing a job via the Null adapter is a non-throwing operation and produces an observable log.
     *
     * Test approach:
     * - Swap Laravel's logger with an in-memory collector to capture log entries without relying on the filesystem.
     * - Configure the default adapter to `null` and dispatch a job using the public `push()` API.
     *
     * Validates:
     * - A log record is created at `info` level with the expected message.
     * - The log context includes the minimum structured fields used across adapters:
     *   `uuid`, `job_class`, `topic`, `payload`, `timestamp`.
     *
     * Out of scope:
     * - Exact payload encoding for real transports (SNS / Pub/Sub).
     * - Whether the log is emitted to a particular channel/handler (only the payload is asserted).
     */
    public function test_null_adapter_logs_payload_and_does_not_throw(): void
    {
        config()->set('stateless-queue.default', 'null');

        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level:string,message:string,context:array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        Log::swap($logger);

        $job = new NullAdapterTestJob('from-null-adapter');
        $job->push();

        $this->assertNotEmpty($logger->records);
        $record = $logger->records[0];

        $this->assertSame('info', $record['level']);
        $this->assertSame('StatelessQueue (NullAdapter): Job pushed', $record['message']);

        $context = $record['context'];

        $this->assertIsArray($context);
        $this->assertArrayHasKey('uuid', $context);
        $this->assertArrayHasKey('job_class', $context);
        $this->assertArrayHasKey('topic', $context);
        $this->assertArrayHasKey('payload', $context);
        $this->assertArrayHasKey('timestamp', $context);

        $this->assertSame(NullAdapterTestJob::class, $context['job_class']);
    }

    /**
     * Pins the behaviour that makes this adapter unsafe in production.
     *
     * The null adapter is the package default, so an application that never sets
     * STATELESS_QUEUE_ADAPTER gets it. It publishes nothing — every job is silently dropped — and it
     * writes the whole message, payload included, to the log at `info` level, which is a level most
     * deployments retain and ship to an aggregator.
     *
     * This test exists so the hazard is a pinned, visible property rather than a line in the docs.
     * If the adapter is ever changed to redact payloads, this test fails and the README, config
     * comment, SECURITY.md checklist and class docblock all need updating with it.
     *
     * Validates:
     * - Payload values are present in the log context verbatim, not redacted or truncated.
     * - The level is `info`, not `debug`.
     */
    public function test_null_adapter_logs_payload_values_verbatim_at_info_level(): void
    {
        // Given
        config()->set('stateless-queue.default', 'null');

        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level:string,message:string,context:array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        Log::swap($logger);

        // Stands in for the personal data a real job payload carries.
        $sensitive = 'jane.doe@example.com|card-ending-4242';

        // When
        (new NullAdapterTestJob($sensitive))->push();

        // Then
        $this->assertNotEmpty($logger->records);
        $record = $logger->records[0];

        $this->assertSame('info', $record['level'], 'Payload logging happens at a retained level.');
        $this->assertContains(
            $sensitive,
            $record['context']['payload'],
            'The null adapter logs payload values verbatim — this is why it is development-only.',
        );
    }
}

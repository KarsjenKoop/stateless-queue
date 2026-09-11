<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Dispatch;

use Karsjen\StatelessQueue\Contracts\QueueProviderAdapter;
use Karsjen\StatelessQueue\Contracts\OutboundAdapter;
use Karsjen\StatelessQueue\Tests\TestCase;
use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Tests\Support\Jobs\DispatchTestJob;
use Mockery;

/**
 * Dispatch contract: pushing a stateless job delegates to the configured adapter.
 *
 * Context:
 * - Stateless jobs call `push()` (via `CanStatelessQueue`) which resolves a `QueueProviderAdapter`
 *   and hands it an `OutgoingJobMessage`.
 * - This test pins the container interaction (adapter resolution/binding) and the payload
 *   shape passed to the adapter.
 *
 * Validates:
 * - Calling `push()` on a job results in a single adapter `publish()` call with a correct payload.
 *
 * Does not validate:
 * - Adapter transport behavior (covered by adapter unit tests and E2E tests).
 */
class DispatchTest extends TestCase
{
    /**
     * Proves that dispatching a stateless job delegates to the configured adapter with the expected payload.
     *
     * Unit boundary (feature-level):
     * - Exercises container binding + the `push()` integration point (job -> adapter).
     * - Does not call any real broker clients; `QueueProviderAdapter` is mocked.
     *
     * Validates:
     * - `QueueProviderAdapter::publish()` is called exactly once.
     * - The pushed `OutgoingJobMessage` contains:
     *   - `jobClass` equal to the dispatched job’s class,
     *   - `topic` equal to the job’s declared topic,
     *   - `payload` equal to the job’s constructor data (wire payload).
     *
     */
    public function test_it_pushes_job_to_adapter(): void
    {
        // Given
        $mockAdapter = Mockery::mock(OutboundAdapter::class);
        $mockAdapter->shouldReceive('publish')
            ->once()
            ->with(Mockery::on(function (OutgoingJobMessage $payload) {
                $data = $payload->payload;
                return $payload->jobClass === DispatchTestJob::class
                    && $payload->topic === 'custom-topic'
                    && is_array($data)
                    && ($data['message'] ?? null) === 'Hello World';
            }));
        $mockAdapter->shouldReceive('name')->andReturn('null');

        // When
        $this->app->instance(OutboundAdapter::class, $mockAdapter);

        (new DispatchTestJob('Hello World'))->push();
    }
}
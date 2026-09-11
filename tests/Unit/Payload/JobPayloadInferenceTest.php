<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Payload;

use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithDependency;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithMixedDataParams;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithNoConstructor;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithOnlyDependency;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithOptionalParam;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithScalarParams;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithUntypedParam;
use Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Payload inference rules for `CanStatelessQueue::getPayload()`.
 *
 * Unit boundary:
 * - Inside: job-side inference logic that decides which constructor params become the wire payload array.
 * - Outside: transport encoding, adapter behavior, and external brokers.
 *
 * Why this matters:
 * - Stateless queueing requires that only JSON-friendly “data” crosses the wire; container services and
 *   class-typed dependencies must not leak into payloads.
 *
 * Validates:
 * - Payload is inferred from promoted constructor properties (scalars/arrays/bools).
 * - Class-typed constructor params (DI objects) are excluded from payload.
 * - Jobs with no “data params” produce an empty payload.
 */
class JobPayloadInferenceTest extends TestCase
{
    /**
     * Verifies promoted scalar constructor properties are surfaced as payload key/value pairs.
     *
     * Validates:
     * - `getPayload()` maps promoted constructor property names to payload keys.
     * - Values remain unchanged (no coercion beyond PHP assignment semantics).
     *
     * Out of scope:
     * - JSON encoding and transport envelopes.
     */
    public function test_infers_payload_from_promoted_constructor_properties(): void
    {
        // Given
        $job = new JobWithScalarParams('hello', 42);

        // When
        $payload = $job->getPayload();

        // Then
        $this->assertSame(['message' => 'hello', 'count' => 42], $payload);
    }

    /**
     * Ensures JSON-friendly types (arrays/scalars/bools) are included unchanged in the inferred payload.
     *
     * Validates:
     * - Arrays are preserved as arrays.
     * - Scalars/bools are preserved without flattening/coercion.
     *
     * Out of scope:
     * - Encoding into a broker-specific message format.
     */
    public function test_includes_arrays_and_scalars_in_payload(): void
    {
        // Given
        $job = new JobWithMixedDataParams('id-1', ['key' => 'value'], true);

        // When
        $payload = $job->getPayload();

        // Then
        $this->assertSame('id-1', $payload['id']);
        $this->assertSame(['key' => 'value'], $payload['meta']);
        $this->assertTrue($payload['flag']);
    }

    /**
     * Confirms that class-typed constructor properties (DI objects) cause an exception.
     *
     * Why this matters:
     * - Prevents accidental serialization of non-encodable service objects that are stored as properties.
     */
    public function test_throws_exception_for_class_typed_constructor_properties(): void
    {
        // Given
        $dependency = new \stdClass();
        $job = new JobWithDependency($dependency, 'message');

        // Then
        $this->expectException(InvalidStatelessJobPayloadException::class);
        $this->expectExceptionMessage('unsupported type [stdClass]');

        // When
        $job->getPayload();
    }

    /**
     * Ensures untyped properties that are constructor parameters cause an exception.
     */
    public function test_throws_exception_for_untyped_constructor_properties(): void
    {
        // Given
        $job = new JobWithUntypedParam('some-value');

        // Then
        $this->expectException(InvalidStatelessJobPayloadException::class);
        $this->expectExceptionMessage('must have a built-in type');

        // When
        $job->getPayload();
    }

    /**
     * Ensures jobs with no constructor data produce an empty payload array.
     *
     * Validates:
     * - Return value is always an array, even when empty.
     *
     * Out of scope:
     * - Adapter behavior when given an empty payload.
     */
    public function test_empty_payload_when_constructor_has_no_data_params(): void
    {
        // Given
        $job = new JobWithNoConstructor();

        // When
        $payload = $job->getPayload();

        // Then
        $this->assertSame([], $payload);
    }

    /**
     * Ensures DI-only constructors (no properties) result in an empty payload.
     *
     * Validates:
     * - Constructors comprised solely of class-typed dependencies (that are NOT properties) contribute no wire payload.
     */
    public function test_empty_payload_when_constructor_has_only_di_params(): void
    {
        // Given
        $job = new JobWithOnlyDependency(new \stdClass());

        // When
        $payload = $job->getPayload();

        // Then
        $this->assertSame([], $payload);
    }

    /**
     * Ensures DI parameters (not properties) are skipped silently even when mixed with data properties.
     */
    public function test_skips_di_params_silently_when_not_properties(): void
    {
        $job = new class(new \stdClass(), 'foo') implements \Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue {
            use \Karsjen\StatelessQueue\Traits\CanStatelessQueue;
            public function __construct(\stdClass $service, public string $data) {}
            public function handle(): void {}
        };

        $this->assertSame(['data' => 'foo'], $job->getPayload());
    }

    /**
 * Sanity-checks that inferred payload integrates with outgoing messages as an array payload.
     *
     * Validates:
     * - The inferred array survives into `OutgoingJobMessage::$payload` unchanged.
     *
     * Out of scope:
     * - JSON invariants (covered by `StatelessJobPayloadEncodingTest`).
     */
    public function test_stateless_job_payload_encodes_inferred_payload_as_json_array(): void
    {
        // Given
        $job = new JobWithScalarParams('inferred', 99);

        // When
        config()->set('stateless-queue.default_topic', 'my-topic');
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('createOutgoingMessage');
        $statelessPayload = $method->invoke($job);

        // Then
        $this->assertIsArray($statelessPayload->payload);
        $this->assertSame('inferred', $statelessPayload->payload['message']);
        $this->assertSame(99, $statelessPayload->payload['count']);
    }

    /**
     * Confirms optional/promoted constructor parameters are included when present.
     *
     * Validates:
     * - Both required and optional promoted properties appear in the payload.
     *
     * Out of scope:
     * - Backwards compatibility strategy for changing job signatures (this test pins current behavior).
     */
    public function test_optional_constructor_param_included_when_property_exists(): void
    {
        // Given
        $job = new JobWithOptionalParam('required', 'optional-value');

        // When
        $payload = $job->getPayload();

        // Then
        $this->assertSame(['required' => 'required', 'optional' => 'optional-value'], $payload);
    }
}


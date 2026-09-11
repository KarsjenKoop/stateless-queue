<?php

namespace Karsjen\StatelessQueue\Traits;

use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException;
use Illuminate\Support\Str;
use Karsjen\StatelessQueue\Contracts\OutboundAdapter;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionClass;
use ReflectionType;

/**
 * Trait: CanStatelessQueue
 *
 * Gives a job the sending half of the stateless queue: a named constructor, payload inference from the
 * constructor signature, topic resolution, and publishing through the configured outbound adapter.
 *
 * Provides:
 * - `dispatch(...)`      — static named constructor matching your `__construct` signature.
 * - `dispatchAndPush(...)` — construct and publish in one call.
 * - `push()`             — build the transport envelope and publish it.
 * - `getPayload()`       — infer the JSON payload from the constructor. Override to take control.
 *
 * ### How It Works
 * The job is **never** PHP-serialised. Only a JSON envelope crosses the wire, so the sender and the
 * receiver can be separate deployments. When you call:
 *
 *     MyJob::dispatch('data', 42)->push()
 *
 * 1. `dispatch('data', 42)` instantiates `MyJob` with those constructor arguments.
 * 2. `push()` calls `getPayload()`, wraps the result in an
 *    {@see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage}, and hands it to the
 *    {@see \Karsjen\StatelessQueue\Contracts\OutboundAdapter} bound in the container.
 * 3. The adapter publishes it to the resolved topic (Pub/Sub, SNS, or the null adapter's log).
 *
 * On the receiving side the webhook reinstantiates the job from `job_class` and `payload` and calls
 * `handle()`.
 *
 * ### Example: Defining and Using a Stateless Job
 * ```php
 * use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
 * use Karsjen\StatelessQueue\Traits\CanStatelessQueue;
 *
 * class SendWelcomeEmail implements ShouldStatelessQueue
 * {
 *     use CanStatelessQueue;
 *
 *     public function __construct(
 *         public string $email,
 *         public string $name = 'Customer',
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         // Your email sending logic here
 *         \Mail::to($this->email)->send(new WelcomeMail($this->name));
 *     }
 * }
 *
 * // Later—dispatch immediately to the configured queue:
 * SendWelcomeEmail::dispatch('jane@example.com', 'Jane')->push();
 * ```
 *
 * ### Customizing Serialization (Payload)
 * By default, the trait will turn your job's public constructor arguments into the transport payload.
 * If you need to customize what's sent (e.g., omit a property, add extra computed fields, encrypt values, etc),
 * you can override `getPayload()` in your job class:
 * ```php
 * public function getPayload(): array
 * {
 *     // Custom shape or filter fields
 *     return [
 *         'email' => $this->email,
 *         // Add more logic here...
 *     ];
 * }
 * ```
 *
 * Only the output of `getPayload()` will be serialized and sent across the wire.
 *
 * > Note: Only simple types (string, int, float, bool, array) are included by default in the serialization logic.
 * > Constructor-injected services/classes won't be serialized, by design.
 *
 * @see \Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue
 * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
 * @see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage
 */
trait CanStatelessQueue
{
    /**
     * Create a new job instance with the given constructor arguments.
     *
     * This is a named constructor, not Laravel's `Dispatchable::dispatch()` — it queues nothing on its
     * own. Chain `push()` to publish the job: `MyJob::dispatch('a', 1)->push()`.
     *
     * @param  mixed  ...$arguments  Constructor arguments for the job.
     * @return static
     */
    final public static function dispatch(...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Create a new job instance with the given constructor arguments and push it to the stateless queue.
     *
     * @param  mixed  ...$arguments  Constructor arguments for the job.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException
     *         if a constructor parameter cannot be serialised for transport.
     * @throws \Karsjen\StatelessQueue\Exceptions\AdapterPublishException
     *         if the configured adapter fails to publish.
     */
    final public static function dispatchAndPush(...$arguments): void
    {
        $job = new static(...$arguments);
        $job->push();
    }

    /**
     * Push the job onto the stateless queue using the configured outbound adapter.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException
     *         if a constructor parameter is untyped or not JSON-serialisable.
     * @throws \Karsjen\StatelessQueue\Exceptions\AdapterPublishException
     *         if the provider rejects the publish, e.g. on a network, auth, or missing-topic error.
     * @throws \RuntimeException if the configured `stateless-queue.default` adapter is unknown.
     */
    final public function push(): void
    {
        $payload = $this->createOutgoingMessage();
        app(OutboundAdapter::class)->publish($payload);
    }

    /**
     * Get the payload array that will be sent over the stateless queue for this job.
     *
     * Reconstructs, by reflection, the data needed to re-instantiate the job on the receiving side using
     * only JSON-serialisable values.
     *
     * ### How it works
     * Each `__construct()` parameter is classified by name and type:
     *
     * | Parameter                                            | Result                                    |
     * |------------------------------------------------------|-------------------------------------------|
     * | Not a property on the job (plain DI argument)         | Skipped; re-resolved from the container   |
     * | Built-in type, or a union of built-in types           | Current property value is included        |
     * | No type declaration                                   | `InvalidStatelessJobPayloadException`     |
     * | Class type or intersection type                       | `InvalidStatelessJobPayloadException`     |
     *
     * Note that a *promoted* constructor property is a property, so a promoted service dependency is
     * rejected rather than skipped. Resolve services inside `handle()` instead.
     *
     * The result is a `name => value` map reflecting the property values at the moment `push()` is
     * called, JSON-encoded on transport.
     *
     * ### What to expect
     * - Only scalar and array values ever leave the process — never closures, resources, or services.
     * - Failures are loud: an unserialisable parameter throws rather than silently vanishing from the
     *   payload and reappearing as a missing-argument error on the receiver.
     * - Override this method to control the payload shape exactly.
     *
     * @see \Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue For more contract details.
     *
     * @return array<string, mixed> The payload data for JSON serialization.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException
     *         if a constructor parameter that is stored as a property is untyped, or is typed as a
     *         class or intersection type that cannot be JSON-encoded.
     */
    public function getPayload(): array
    {
        $ref = new ReflectionClass($this);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $payload = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            // Only attempt to serialize parameters that are stored as properties on the job.
            // DI-only parameters (e.g. services) are skipped silently.
            if (! property_exists($this, $name)) {
                continue;
            }

            $type = $param->getType();

            if ($type === null) {
                throw InvalidStatelessJobPayloadException::untypedProperty(static::class, $name);
            }

            if (! $this->isTypeSafeForPayload($type)) {
                throw InvalidStatelessJobPayloadException::nonSerializableProperty(static::class, $name, (string) $type);
            }

            $payload[$name] = $this->{$name};
        }

        return $payload;
    }

    /**
     * Build the outgoing message envelope for this job.
     *
     * Stamps a fresh UUID and the current Unix timestamp, and resolves the topic. Override to add
     * transport attributes or to control the UUID.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException via getPayload().
     */
    protected function createOutgoingMessage(): OutgoingJobMessage
    {
        return new OutgoingJobMessage(
            uuid: (string) Str::uuid(),
            jobClass: static::class,
            topic: $this->resolveTopic(),
            payload: $this->getPayload(),
            timestamp: time(),
        );
    }

    /**
     * Resolve the topic for this job.
     *
     * Precedence: `$statelessTopic`, then `$stateless_topic`, then
     * `config('stateless-queue.default_topic')`.
     */
    protected function resolveTopic(): string
    {
        if (property_exists($this, 'statelessTopic') && ! empty($this->statelessTopic)) {
            return $this->statelessTopic;
        }

        if (property_exists($this, 'stateless_topic') && ! empty($this->stateless_topic)) {
            return $this->stateless_topic;
        }

        return config('stateless-queue.default_topic', 'default_stateless_queue');
    }

    /**
     * Whether a reflected parameter type can be represented in a JSON payload.
     *
     * Accepts built-in types and unions composed entirely of built-in types. Rejects class types,
     * intersection types, and the absence of a type declaration.
     */
    private function isTypeSafeForPayload(?ReflectionType $type): bool
    {
        if($type === null) {
            return false;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if (! $t instanceof ReflectionNamedType || ! $t->isBuiltin()) {
                    return false;
                }
            }
            return true;
        }

        if ($type instanceof ReflectionIntersectionType) {
            return false;
        }

        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin();
        }

        return false;
    }
}
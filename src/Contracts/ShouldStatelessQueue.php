<?php

namespace Karsjen\StatelessQueue\Contracts;

/**
 * Contract: ShouldStatelessQueue
 *
 * Marker interface for jobs that can be pushed to the stateless queue.
 * Classes implementing this contract must provide:
 *   - getPayload(): Returns the job's payload for transport (JSON-serializable).
 *   - handle(): Executes the job logic after payload reconstitution.
 *
 * If using {@see \Karsjen\StatelessQueue\Traits\CanStatelessQueue},
 * you get a default implementation of getPayload() inferring values from the constructor signature
 * and public properties (simple types only).
 *
 * Implement this interface on your job if it should be eligible for stateless queueing,
 * e.g. for use with cloud pub/sub transports (SNS, Pub/Sub).
 *
 * Example:
 * ```php
 * use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
 * use Karsjen\StatelessQueue\Traits\CanStatelessQueue;
 * 
 * class MyJob implements ShouldStatelessQueue
 * {
 *     use CanStatelessQueue;
 *     
 *     public function __construct(
 *         public string $foo,
 *         public int $bar = 123
 *     ) {}
 * 
 *     public function handle(): void
 *     {
 *         // Job logic here
 *     }
 * }
 * ```
 *
 * @see \Karsjen\StatelessQueue\Traits\CanStatelessQueue
 * @see \Karsjen\StatelessQueue\Contracts\QueueProviderAdapter
 * @see \Karsjen\StatelessQueue\StatelessQueueServiceProvider
 */
interface ShouldStatelessQueue
{
    /**
     * Return the constructor arguments to send over the queue (JSON-serializable).
     * On the webhook the job is reinstantiated with these and handle() is called.
     *
     * CanStatelessQueue provides a default that infers this from your constructor
     * (parameter names → same-named properties). Override only if you need custom behaviour.
     *
     * @return array<string, mixed> Named constructor arguments; must be JSON-encodable.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\InvalidStatelessJobPayloadException
     *         from the trait's default implementation when a parameter cannot be serialised.
     */
    public function getPayload(): array;

    /**
     * Execute the job.
     *
     * Invoked by {@see \Karsjen\StatelessQueue\Runtime\JobRunner} after the incoming payload has been
     * authenticated, allowlisted, and used to reinstantiate the job. Treat every value that came from
     * the payload as untrusted input.
     *
     * Throwing from here produces a 500 response, which tells the provider that delivery failed and
     * lets its retry and dead-letter policy take over.
     */
    public function handle(): void;
}
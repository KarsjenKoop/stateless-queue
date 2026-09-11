<?php

namespace Karsjen\StatelessQueue\Contracts;

use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;

/**
 * Contract: OutboundAdapter
 *
 * Contract for adapters that publish outgoing job messages to a queue provider (SNS, Pub/Sub, etc.).
 *
 * The implementation bound to this contract is the one named by `stateless-queue.default`; it is what
 * {@see \Karsjen\StatelessQueue\Traits\CanStatelessQueue::push()} resolves from the container.
 *
 * @see \Karsjen\StatelessQueue\Contracts\QueueProviderAdapter
 * @see \Karsjen\StatelessQueue\Adapters\NullAdapter
 */
interface OutboundAdapter
{
    /** Short provider identifier used in log context and error messages, e.g. `aws` or `google`. */
    public function name(): string;

    /**
     * Publish the message to the provider, on the topic named by the message.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\AdapterPublishException
     *         if the provider rejects the publish. Wrap the underlying SDK exception as `$previous`.
     */
    public function publish(OutgoingJobMessage $message): void;
}

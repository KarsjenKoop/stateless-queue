<?php

namespace Karsjen\StatelessQueue\Exceptions;

use RuntimeException;

/**
 * Exception: AdapterPublishException
 *
 * Thrown when an outbound adapter fails to publish a job message to its queue provider.
 * Wraps the underlying provider exception as the previous throwable for full stack trace access.
 *
 * Catching this exception specifically allows the application to implement retry logic,
 * fallback queues, or publish-failure alerting independently of other runtime errors.
 *
 * @see \Karsjen\StatelessQueue\Adapters\AwsSnsAdapter
 * @see \Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter
 * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
 */
final class AdapterPublishException extends RuntimeException
{
    /** Raised when the adapter fails to deliver the message to the queue provider. */
    public static function forAdapter(string $adapterName, string $message, ?\Throwable $previous = null): self
    {
        return new self("StatelessQueue [{$adapterName}] publish failed: {$message}", 0, $previous);
    }
}

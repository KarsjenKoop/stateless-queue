<?php

namespace Karsjen\StatelessQueue\Messages;

use JsonException;
use RuntimeException;

/**
 * Message: OutgoingJobMessage
 *
 * Represents a job payload ready to be published to a queue provider.
 *
 * Created by CanStatelessQueue::createOutgoingMessage() and passed to an OutboundAdapter for publishing.
 * Extends JobMessage with JSON serialisation for wire transport.
 *
 * @see \Karsjen\StatelessQueue\Traits\CanStatelessQueue
 * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
 * @see \Karsjen\StatelessQueue\Messages\JobMessage
 */
final class OutgoingJobMessage extends JobMessage
{
    /**
     * Serialises the message to a JSON string for wire transport.
     *
     * @throws \RuntimeException if the payload contains non-JSON-encodable values
     *         (for example a resource, a closure, or an invalid UTF-8 string).
     */
    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'OutgoingJobMessage: payload is not JSON-encodable. '.$e->getMessage(),
                0,
                $e
            );
        }
    }
}

<?php

namespace Karsjen\StatelessQueue\Messages;

/**
 * Message: JobMessage
 *
 * Abstract base DTO representing a serialised job in transit between the publisher and the webhook receiver.
 *
 * Carries the job class name, topic, UUID, payload, timestamp, and optional transport attributes.
 * Concrete subclasses handle direction-specific concerns:
 * - {@see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage} — adds JSON serialisation for publishing.
 * - {@see \Karsjen\StatelessQueue\Messages\IncomingJobMessage} — adds factory methods for deserialising from adapters.
 *
 * @see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage
 * @see \Karsjen\StatelessQueue\Messages\IncomingJobMessage
 */
abstract class JobMessage
{
    public const DEFAULT_VERSION = 1;

    public function __construct(
        public readonly string $uuid,
        public readonly string $jobClass,
        public readonly string $topic,
        public readonly array $payload,
        public readonly int $timestamp,
        public readonly int $version = self::DEFAULT_VERSION,
        public readonly array $attributes = [],
        public readonly ?string $source = null,
    ) {}

    /**
     * Returns the core fields as a plain array for logging and JSON encoding.
     * Transport-only fields (attributes, source) are intentionally excluded.
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'job_class' => $this->jobClass,
            'topic' => $this->topic,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
            'version' => $this->version,
        ];
    }
}

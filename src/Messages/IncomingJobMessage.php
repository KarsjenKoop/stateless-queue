<?php

namespace Karsjen\StatelessQueue\Messages;

use JsonException;
use Karsjen\StatelessQueue\Exceptions\WebhookParseException;

/**
 * Message: IncomingJobMessage
 *
 * Represents a job payload received from a queue provider webhook.
 *
 * Created by adapters after they have verified and decoded the raw HTTP request.
 * Passed to the JobRunner for allowlist validation and execution.
 *
 * @see \Karsjen\StatelessQueue\Runtime\JobRunner
 * @see \Karsjen\StatelessQueue\Messages\JobMessage
 */
final class IncomingJobMessage extends JobMessage
{
    /**
     * Canonical constructor from already-normalised fields.
     *
     * @param  array<string, mixed>  $data        Decoded envelope: uuid, job_class, topic, payload, timestamp, version.
     * @param  array<string, mixed>  $attributes  Provider message attributes, carried through untouched.
     * @param  string|null           $source      Provider identifier, e.g. `aws_sns` or `google_pubsub`.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\WebhookParseException
     *         if `job_class` is not a string or `payload` is not an array.
     */
    public static function fromArray(array $data, array $attributes = [], ?string $source = null): self
    {
        $jobClass = $data['job_class'] ?? null;
        $payload = $data['payload'] ?? null;

        if (!is_string($jobClass) || !is_array($payload)) {
            throw WebhookParseException::invalidStructure();
        }

        return new self(
            uuid: (string) ($data['uuid'] ?? ''),
            jobClass: $jobClass,
            topic: (string) ($data['topic'] ?? 'unknown'),
            payload: $payload,
            timestamp: (int) ($data['timestamp'] ?? time()),
            version: (int) ($data['version'] ?? self::DEFAULT_VERSION),
            attributes: $attributes,
            source: $source,
        );
    }

    /**
     * Convenience factory for adapters that receive raw JSON strings from the transport layer.
     *
     * @param  string                $json        Raw JSON envelope as delivered by the provider.
     * @param  array<string, mixed>  $attributes  Provider message attributes, carried through untouched.
     * @param  string|null           $source      Provider identifier, e.g. `aws_sns` or `google_pubsub`.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\WebhookParseException
     *         if the string is not valid JSON, does not decode to an object, or is missing required fields.
     */
    public static function fromJson(string $json, array $attributes = [], ?string $source = null): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw WebhookParseException::invalidJson($e->getMessage(), $e);
        }

        if (!is_array($decoded)) {
            throw WebhookParseException::notAnObject();
        }

        return self::fromArray($decoded, $attributes, $source);
    }
}

<?php

namespace Karsjen\StatelessQueue\Exceptions;

use RuntimeException;

/**
 * Exception: WebhookParseException
 *
 * Thrown when an incoming webhook payload cannot be parsed into a valid IncomingJobMessage.
 * Covers missing or wrong-typed fields, invalid JSON, and unexpected payload shapes.
 *
 * Named constructors describe the specific parse failure so callers can log or respond with
 * appropriate detail without inspecting the exception message string.
 *
 * @see \Karsjen\StatelessQueue\Messages\IncomingJobMessage
 * @see \Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter
 */
final class WebhookParseException extends RuntimeException
{
    /** Raised when the payload is missing required fields or they are of the wrong type. */
    public static function invalidStructure(string $detail = 'Invalid payload structure'): self
    {
        return new self($detail);
    }

    /** Raised when the raw payload string is not valid JSON. */
    public static function invalidJson(string $detail, ?\Throwable $previous = null): self
    {
        return new self('Invalid JSON payload: '.$detail, 0, $previous);
    }

    /** Raised when the decoded JSON value is not an object (array in PHP). */
    public static function notAnObject(): self
    {
        return new self('Decoded payload is not a JSON object.');
    }
}

<?php

namespace Karsjen\StatelessQueue\Exceptions;

use RuntimeException;

/**
 * Exception: InvalidStatelessJobPayloadException
 *
 * Thrown by CanStatelessQueue::getPayload() when a constructor parameter cannot be safely serialised for transport.
 *
 * Two named constructors cover the two failure modes:
 * - `untypedProperty`         — the parameter has no type declaration; the serialiser cannot determine if it is safe.
 * - `nonSerializableProperty` — the parameter is typed as a class, intersection type, or other non-built-in type.
 *
 * @see \Karsjen\StatelessQueue\Traits\CanStatelessQueue
 */
final class InvalidStatelessJobPayloadException extends RuntimeException
{
    /** Raised when a constructor parameter has no type declaration. */
    public static function untypedProperty(string $jobClass, string $propertyName): self
    {
        return new self("Stateless job [{$jobClass}] property [\${$propertyName}] must have a built-in type (e.g., string, int, bool, array) to be automatically serialized. Untyped properties are not allowed.");
    }

    /** Raised when a constructor parameter is typed as a class or intersection type that cannot be JSON-encoded. */
    public static function nonSerializableProperty(string $jobClass, string $propertyName, string $type): self
    {
        return new self("Stateless job [{$jobClass}] property [\${$propertyName}] has an unsupported type [{$type}]. Only built-in types or unions of built-in types are supported for automatic serialization.");
    }
}

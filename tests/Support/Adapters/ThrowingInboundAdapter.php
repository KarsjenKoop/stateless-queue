<?php

namespace Karsjen\StatelessQueue\Tests\Support\Adapters;

use Illuminate\Http\Request;
use Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter;
use Karsjen\StatelessQueue\Parsing\ParseResult;

/**
 * Fixture: an inbound adapter that claims every request and throws a configurable exception from
 * parseRequest().
 *
 * Lets controller tests drive the parse-failure branches directly, including the "adapter blew up
 * unexpectedly" path that no real adapter can be coaxed into reaching.
 */
final class ThrowingInboundAdapter implements InboundWebhookAdapter
{
    /** The exception parseRequest() will throw. */
    public static ?\Throwable $toThrow = null;

    public function name(): string
    {
        return 'throwing-test-adapter';
    }

    public function supportsRequest(Request $request): bool
    {
        return true;
    }

    public function verifySignature(Request $request): bool
    {
        return true;
    }

    public function parseRequest(Request $request): ParseResult
    {
        if (self::$toThrow !== null) {
            throw self::$toThrow;
        }

        return ParseResult::ignore();
    }
}

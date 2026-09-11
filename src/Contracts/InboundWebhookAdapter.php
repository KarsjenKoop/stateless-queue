<?php

namespace Karsjen\StatelessQueue\Contracts;

use Karsjen\StatelessQueue\Parsing\ParseResult;
use Illuminate\Http\Request;

/**
 * Contract: InboundWebhookAdapter
 *
 * Contract for adapters that receive and verify incoming webhook payloads from a queue provider.
 *
 * The three methods are called in order, and each is a distinct stage of the request lifecycle:
 * `supportsRequest()` claims the request, `verifySignature()` authenticates it, and `parseRequest()`
 * turns it into a {@see \Karsjen\StatelessQueue\Parsing\ParseResult}. Only the first two are reached
 * for a request that fails authentication.
 *
 * Implementations must be safe to construct without network access — the registry resolves every
 * registered adapter in order to probe it.
 *
 * @see \Karsjen\StatelessQueue\Contracts\QueueProviderAdapter
 * @see \Karsjen\StatelessQueue\Runtime\ProviderRegistry
 * @see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature
 */
interface InboundWebhookAdapter
{
    /** Short provider identifier used in log context and error messages, e.g. `aws` or `google`. */
    public function name(): string;

    /**
     * Returns true if this adapter recognises the incoming request's structure or headers.
     *
     * Called on every registered adapter in turn until one claims the request, so this must be cheap
     * and must not throw. Prefer a header check, falling back to a body shape check.
     */
    public function supportsRequest(Request $request): bool;

    /**
     * Verify the provider's signature or token.
     *
     * Return false to reject the request with 403 — do not throw, and never log the token, signature,
     * or any header value that carries credentials.
     */
    public function verifySignature(Request $request): bool;

    /**
     * Parse a verified request into a ParseResult (job, handshake, ignore, or invalid).
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\WebhookParseException
     *         if the payload cannot be parsed. The controller converts this into a 422 response.
     */
    public function parseRequest(Request $request): ParseResult;
}

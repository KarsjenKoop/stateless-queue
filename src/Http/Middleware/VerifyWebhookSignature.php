<?php

namespace Karsjen\StatelessQueue\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Closure;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;

/**
 * Middleware: VerifyWebhookSignature
 *
 * Authenticates incoming webhook requests before they reach the controller.
 *
 * ### How It Works
 * 1. In local/testing environments, bypasses verification when `allow_local_unverified` is enabled.
 * 2. Resolves the matching InboundWebhookAdapter from the ProviderRegistry via header/payload sniffing.
 * 3. Delegates signature or token verification to the resolved adapter.
 *    On success, stashes the adapter on the request attributes to avoid re-resolving it in the controller.
 * 4. Falls back to a shared webhook secret (`stateless-queue.webhook_secret`) passed as a query param,
 *    compared with hash_equals() so the check does not leak the secret through response timing.
 * 5. Rejects with 401/403 if no verification path passes.
 *
 * @see \Karsjen\StatelessQueue\Runtime\ProviderRegistry
 * @see \Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter
 * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
 */
class VerifyWebhookSignature
{
    private const REQUEST_ADAPTER_ATTRIBUTE = '_stateless_queue_adapter';

    public function __construct(private ProviderRegistry $registry) {}

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('StatelessQueue: Signature verification', [
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        if (app()->environment(['local', 'testing']) && config('stateless-queue.allow_local_unverified', false)) {
            Log::info('StatelessQueue: Local/testing bypass enabled');
            return $next($request);
        }

        $adapter = $this->registry->resolveForRequest($request);

        if ($adapter) {
            if ($adapter->verifySignature($request)) {
                $request->attributes->set(self::REQUEST_ADAPTER_ATTRIBUTE, $adapter);
                return $next($request);
            }

            Log::error("StatelessQueue: Signature verification failed for adapter [{$adapter->name()}]");

            $error = match ($adapter->name()) {
                'aws'    => 'Invalid AWS Signature',
                'google' => 'Invalid Google Token',
                default  => "Invalid {$adapter->name()} signature",
            };

            return response()->json(['error' => $error], 403);
        }

        if ($this->secretMatches($request)) {
            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized: Missing or Invalid Signature'], 401);
    }

    /**
     * Whether the request carries the configured shared secret as a `?secret=` query parameter.
     *
     * Compared with hash_equals(): this is a secret checked against attacker-supplied input, and a
     * plain `===` short-circuits on the first differing byte. That timing difference is measurable
     * over enough requests and lets a caller recover the secret one byte at a time.
     *
     * The query value is type-checked before comparison — `?secret[]=x` arrives as an array, which
     * would make hash_equals() raise a TypeError and turn a failed auth attempt into a 500.
     *
     * Returns false when no secret is configured, so leaving `webhook_secret` unset closes this path
     * entirely rather than accepting an empty one.
     */
    private function secretMatches(Request $request): bool
    {
        $secret = config('stateless-queue.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = $request->query('secret');

        if (! is_string($provided)) {
            return false;
        }

        return hash_equals($secret, $provided);
    }
}

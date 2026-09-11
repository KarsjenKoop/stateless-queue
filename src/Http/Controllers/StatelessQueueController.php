<?php

namespace Karsjen\StatelessQueue\Http\Controllers;

use Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter;
use Karsjen\StatelessQueue\Contracts\JobExecutor;
use Karsjen\StatelessQueue\Exceptions\JobNotAllowedException;
use Karsjen\StatelessQueue\Exceptions\WebhookParseException;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller: StatelessQueueController
 *
 * Handles all incoming webhook payloads from queue provider adapters (SNS, Pub/Sub, etc.).
 *
 * ### How It Works
 * The matched adapter is stashed on the request by {@see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature}
 * to avoid re-resolving it here. If not present (e.g. secret-token auth), it is resolved again from the registry.
 *
 * Once an adapter is found, it parses the request into a ParseResult. On a job result, the
 * JobRunner validates the class against the allowlist and executes it synchronously.
 *
 * Input:  HTTP POST from a queue provider (SNS notification, Pub/Sub push, etc.).
 * Output: JSON response — 200 on success/handshake/ignore, 400/422/500 on failure.
 *
 * @see \Karsjen\StatelessQueue\Runtime\ProviderRegistry
 * @see \Karsjen\StatelessQueue\Contracts\JobExecutor
 * @see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature
 */
class StatelessQueueController extends Controller
{
    private const REQUEST_ADAPTER_ATTRIBUTE = '_stateless_queue_adapter';

    public function __construct(
        private ProviderRegistry $registry,
        private JobExecutor $runner,
    ) {}

    /**
     * Handles one inbound webhook delivery.
     *
     * Never throws — every failure is converted into a JSON response so the provider receives a
     * definitive status code and can apply its own retry and dead-letter policy.
     *
     * Response bodies are fixed strings. The webhook is a public endpoint, so exception text — which
     * can carry file paths, SQL fragments, class names, or connection strings — goes to the log only.
     * The `error` values below are the complete set a caller can ever observe.
     *
     * | Outcome                                        | Status |
     * |------------------------------------------------|--------|
     * | Job executed, handshake, or ignored message     | 200    |
     * | No adapter recognised the request               | 400    |
     * | Adapter reported a malformed payload            | 422    |
     * | Adapter failed unexpectedly while parsing       | 500    |
     * | Job class is not in the allowlist               | 403    |
     * | Job execution failed                            | 500    |
     *
     * @see \Karsjen\StatelessQueue\Contracts\JobExecutor
     */
    public function handle(Request $request): JsonResponse
    {
        $adapter = $request->attributes->get(self::REQUEST_ADAPTER_ATTRIBUTE);
        if (! $adapter instanceof InboundWebhookAdapter) {
            $adapter = $this->registry->resolveForRequest($request);
        }

        if ($adapter === null) {
            Log::warning('StatelessQueue: No adapter found for incoming request');
            return response()->json(['error' => 'Unknown payload source'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $adapter->parseRequest($request);
        } catch (WebhookParseException $e) {
            // The provider sent something we cannot interpret. This is a client-side fault, so the
            // provider should not keep retrying it unchanged.
            Log::warning('StatelessQueue: Failed to parse webhook payload', [
                'adapter' => $adapter->name(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid payload structure'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            // The adapter itself failed unexpectedly. That is our fault, not the caller's, so report it
            // as a server error and let the provider's retry policy take over.
            Log::error('StatelessQueue: Unexpected error while parsing webhook payload', [
                'adapter' => $adapter->name(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to process webhook payload'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (! $result->isJob()) {
            if ($result->error !== null) {
                Log::warning('StatelessQueue: Adapter parse returned non-job result', [
                    'adapter' => $adapter->name(),
                    'kind' => $result->kind,
                    'error' => $result->error,
                ]);
            }

            return response()->json($result->httpBody, $result->httpStatus);
        }

        try {
            $this->runner->run($result->message);
            Log::info('StatelessQueue: Job completed successfully');
        } catch (JobNotAllowedException $e) {
            // A rejected job class is an authorisation decision, not a transient failure. Answering 500
            // would tell the provider to retry, so a caller probing for executable classes would get
            // their payload replayed until the topic's dead-letter policy gave up.
            Log::warning('StatelessQueue: Rejected job class not in allowlist', [
                'adapter' => $adapter->name(),
                'job_class' => $result->message->jobClass,
            ]);

            return response()->json(['error' => 'Job class not allowed'], Response::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            Log::error('StatelessQueue: Job execution failed', [
                'adapter' => $adapter->name(),
                'job_class' => $result->message->jobClass,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Job execution failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json($result->httpBody, $result->httpStatus);
    }
}

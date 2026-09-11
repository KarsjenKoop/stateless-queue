<?php

namespace Karsjen\StatelessQueue\Runtime;

use Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpFoundation\Request;
use RuntimeException;

/**
 * Registry: ProviderRegistry
 *
 * Holds the set of registered InboundWebhookAdapters and resolves the correct one
 * for an incoming request by probing each adapter in registration order.
 * The first adapter whose supportsRequest() returns true is returned.
 *
 * Adapters are resolved lazily from the container on first use and cached for
 * the lifetime of the registry instance (one request cycle).
 *
 * Used by:
 * - {@see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature} — resolves the adapter to verify the signature.
 * - {@see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController} — resolves the adapter to parse the payload.
 *
 * @see \Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter
 * @see \Karsjen\StatelessQueue\StatelessQueueServiceProvider
 */
final class ProviderRegistry
{
    /** @var array<class-string<InboundWebhookAdapter>> */
    private array $adapterClasses;

    /** @var array<class-string<InboundWebhookAdapter>, InboundWebhookAdapter> */
    private array $resolvedByClass = [];

    /**
     * @param array<class-string<InboundWebhookAdapter>> $adapterClasses
     */
    public function __construct(
        private Container $container,
        array $adapterClasses
    ) {
        $this->adapterClasses = array_values($adapterClasses);
    }

    /**
     * Returns the first registered adapter that claims the request, or null if none does.
     *
     * Adapters are probed in registration order, so the order of the array passed to the constructor
     * is significant when two adapters could both match a request.
     */
    public function resolveForRequest(Request $request): ?InboundWebhookAdapter
    {
        foreach ($this->adapterClasses as $class) {
            $adapter = $this->resolveClass($class);
            if ($adapter->supportsRequest($request)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Returns the registered adapter whose name() matches, resolving it from the container if needed.
     *
     * @throws \RuntimeException if no registered adapter reports that name.
     */
    public function byName(string $name): InboundWebhookAdapter
    {
        foreach ($this->adapterClasses as $class) {
            $adapter = $this->resolveClass($class);
            if ($adapter->name() === $name) {
                return $adapter;
            }
        }

        throw new RuntimeException("No queue provider adapter registered for [{$name}]");
    }

    /** @param class-string<InboundWebhookAdapter> $class */
    private function resolveClass(string $class): InboundWebhookAdapter
    {
        if (! isset($this->resolvedByClass[$class])) {
            /** @var InboundWebhookAdapter $adapter */
            $adapter = $this->container->make($class);
            $this->resolvedByClass[$class] = $adapter;
        }

        return $this->resolvedByClass[$class];
    }
}

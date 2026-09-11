<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter;
use Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature;
use Karsjen\StatelessQueue\Parsing\ParseResult;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use Karsjen\StatelessQueue\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Direct tests for {@see VerifyWebhookSignature} branching (registry, secret fallback, bypass).
 *
 * Uses a real {@see ProviderRegistry} with test inbound adapters bound in the container
 * (the registry is final and cannot be doubled).
 */
class VerifyWebhookSignatureMiddlewareTest extends TestCase
{
    /** @see VerifyWebhookSignature (private const REQUEST_ADAPTER_ATTRIBUTE) */
    private const REQUEST_ADAPTER_ATTRIBUTE = '_stateless_queue_adapter';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);
    }

    public function test_local_testing_bypass_skips_registry_and_calls_next(): void
    {
        config()->set('stateless-queue.allow_local_unverified', true);

        $registry = new ProviderRegistry($this->app, []);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook', 'POST', []);

        $called = false;
        $response = $middleware->handle($request, function (Request $req) use (&$called): SymfonyResponse {
            $called = true;

            return new Response(json_encode(['ok' => true]), 200, ['Content-Type' => 'application/json']);
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_sets_adapter_request_attribute_when_verification_succeeds(): void
    {
        $adapter = new MiddlewareProbeInboundAdapter(verify: true, name: 'aws');
        $this->app->instance(MiddlewareProbeInboundAdapter::class, $adapter);

        $registry = new ProviderRegistry($this->app, [MiddlewareProbeInboundAdapter::class]);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook', 'POST', []);

        $seen = null;
        $middleware->handle($request, function (Request $req) use (&$seen): SymfonyResponse {
            $seen = $req->attributes->get(self::REQUEST_ADAPTER_ATTRIBUTE);

            return new Response('{}', 204);
        });

        $this->assertSame($adapter, $seen);
    }

    public function test_returns_403_when_adapter_matches_but_verification_fails(): void
    {
        $adapter = new MiddlewareProbeInboundAdapter(verify: false, name: 'google');
        $this->app->instance(MiddlewareProbeInboundAdapter::class, $adapter);

        $registry = new ProviderRegistry($this->app, [MiddlewareProbeInboundAdapter::class]);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook', 'POST', []);

        $called = false;
        $response = $middleware->handle($request, function () use (&$called): SymfonyResponse {
            $called = true;

            return new Response('{}', 200);
        });

        $this->assertFalse($called);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Invalid Google Token', $response->getContent());
    }

    public function test_returns_401_when_no_adapter_matches_and_secret_missing_or_wrong(): void
    {
        config()->set('stateless-queue.webhook_secret', 'expected-secret');

        $this->app->instance(MiddlewareNeverMatchInboundAdapter::class, new MiddlewareNeverMatchInboundAdapter);
        $registry = new ProviderRegistry($this->app, [MiddlewareNeverMatchInboundAdapter::class]);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook?secret=wrong', 'POST', []);

        $called = false;
        $response = $middleware->handle($request, function () use (&$called): SymfonyResponse {
            $called = true;

            return new Response('{}', 200);
        });

        $this->assertFalse($called);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized: Missing or Invalid Signature', $response->getContent());
    }

    public function test_calls_next_when_no_adapter_matches_but_query_secret_is_valid(): void
    {
        config()->set('stateless-queue.webhook_secret', 'expected-secret');

        $this->app->instance(MiddlewareNeverMatchInboundAdapter::class, new MiddlewareNeverMatchInboundAdapter);
        $registry = new ProviderRegistry($this->app, [MiddlewareNeverMatchInboundAdapter::class]);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook?secret=expected-secret', 'POST', []);

        $called = false;
        $response = $middleware->handle($request, function () use (&$called): SymfonyResponse {
            $called = true;

            return new Response('{}', 200);
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_does_not_fall_back_to_query_secret_when_adapter_failed_verification(): void
    {
        config()->set('stateless-queue.webhook_secret', 'expected-secret');

        $adapter = new MiddlewareProbeInboundAdapter(verify: false, name: 'aws');
        $this->app->instance(MiddlewareProbeInboundAdapter::class, $adapter);

        $registry = new ProviderRegistry($this->app, [MiddlewareProbeInboundAdapter::class]);
        $middleware = new VerifyWebhookSignature($registry);
        $request = Request::create('/api/stateless/webhook?secret=expected-secret', 'POST', []);

        $called = false;
        $response = $middleware->handle($request, function () use (&$called): SymfonyResponse {
            $called = true;

            return new Response('{}', 200);
        });

        $this->assertFalse($called);
        $this->assertSame(403, $response->getStatusCode());
    }
}

/** @internal */
final class MiddlewareProbeInboundAdapter implements InboundWebhookAdapter
{
    public function __construct(
        private bool $verify,
        private string $name = 'aws',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function supportsRequest(Request $request): bool
    {
        return true;
    }

    public function parseRequest(Request $request): ParseResult
    {
        return ParseResult::ignore();
    }

    public function verifySignature(Request $request): bool
    {
        return $this->verify;
    }
}

/** @internal */
final class MiddlewareNeverMatchInboundAdapter implements InboundWebhookAdapter
{
    public function name(): string
    {
        return 'never-match';
    }

    public function supportsRequest(Request $request): bool
    {
        return false;
    }

    public function parseRequest(Request $request): ParseResult
    {
        return ParseResult::ignore();
    }

    public function verifySignature(Request $request): bool
    {
        return true;
    }
}

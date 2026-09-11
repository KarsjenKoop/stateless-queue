<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Runtime;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter;
use Karsjen\StatelessQueue\Parsing\ParseResult;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Inbound adapter resolution order and by-name lookup.
 *
 * Mirrors production registration: AWS before Google (see StatelessQueueServiceProvider);
 * tests use standalone fixture classes to avoid booting the full container.
 */
final class ProviderRegistryTest extends TestCase
{
    public function test_returns_first_adapter_that_supports_the_request(): void
    {
        $first = new FixedSupportInboundAdapter('first', false);
        $second = new FixedSupportInboundAdapter('second', true);

        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnMap([
            [InboundSlotOne::class, [], $first],
            [InboundSlotTwo::class, [], $second],
        ]);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class, InboundSlotTwo::class]);
        $resolved = $registry->resolveForRequest(Request::create('/webhook', 'POST'));

        $this->assertSame($second, $resolved);
        $this->assertSame(1, $first->supportsRequestCallCount);
        $this->assertSame(1, $second->supportsRequestCallCount);
    }

    public function test_stops_at_first_supporting_adapter_without_consulting_later_ones(): void
    {
        $first = new FixedSupportInboundAdapter('first', true);
        $second = $this->createMock(InboundWebhookAdapter::class);
        $second->expects($this->never())->method('supportsRequest');

        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnMap([
            [InboundSlotOne::class, [], $first],
            [InboundSlotTwo::class, [], $second],
        ]);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class, InboundSlotTwo::class]);
        $resolved = $registry->resolveForRequest(Request::create('/webhook', 'POST'));

        $this->assertSame($first, $resolved);
    }

    public function test_returns_null_when_no_adapter_supports_the_request(): void
    {
        $first = new FixedSupportInboundAdapter('first', false);
        $second = new FixedSupportInboundAdapter('second', false);

        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnMap([
            [InboundSlotOne::class, [], $first],
            [InboundSlotTwo::class, [], $second],
        ]);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class, InboundSlotTwo::class]);

        $this->assertNull($registry->resolveForRequest(Request::create('/webhook', 'POST')));
    }

    public function test_by_name_returns_adapter_with_matching_name(): void
    {
        $awsLike = new FixedSupportInboundAdapter('aws', false);
        $googleLike = new FixedSupportInboundAdapter('google', false);

        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnMap([
            [InboundSlotOne::class, [], $awsLike],
            [InboundSlotTwo::class, [], $googleLike],
        ]);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class, InboundSlotTwo::class]);

        $this->assertSame($googleLike, $registry->byName('google'));
    }

    public function test_by_name_throws_when_no_adapter_has_that_name(): void
    {
        $adapter = new FixedSupportInboundAdapter('aws', false);

        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnMap([
            [InboundSlotOne::class, [], $adapter],
        ]);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No queue provider adapter registered for [missing]');

        $registry->byName('missing');
    }

    public function test_reuses_resolved_adapter_instances_from_container(): void
    {
        $adapter = new FixedSupportInboundAdapter('only', true);

        $container = $this->createMock(Container::class);
        $container->expects($this->once())->method('make')->with(InboundSlotOne::class)->willReturn($adapter);

        $registry = new ProviderRegistry($container, [InboundSlotOne::class]);

        $r = Request::create('/webhook', 'POST');
        $this->assertSame($adapter, $registry->resolveForRequest($r));
        $this->assertSame($adapter, $registry->resolveForRequest($r));
        $this->assertSame(2, $adapter->supportsRequestCallCount);
    }
}

/** @internal fixture — class identity used only for ProviderRegistry slot ordering */
final class InboundSlotOne implements InboundWebhookAdapter
{
    public function name(): string
    {
        return 'slot-one';
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

/** @internal fixture */
final class InboundSlotTwo implements InboundWebhookAdapter
{
    public function name(): string
    {
        return 'slot-two';
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

/**
 * @internal Controllable supportsRequest + call counting for tests.
 */
final class FixedSupportInboundAdapter implements InboundWebhookAdapter
{
    public int $supportsRequestCallCount = 0;

    public function __construct(
        private string $adapterName,
        private bool $supports,
    ) {}

    public function name(): string
    {
        return $this->adapterName;
    }

    public function supportsRequest(Request $request): bool
    {
        $this->supportsRequestCallCount++;

        return $this->supports;
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

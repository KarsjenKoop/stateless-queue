<?php

namespace Karsjen\StatelessQueue\Tests\Unit;

use Karsjen\StatelessQueue\Adapters\AwsSnsAdapter;
use Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter;
use Karsjen\StatelessQueue\Adapters\NullAdapter;
use Karsjen\StatelessQueue\Contracts\OutboundAdapter;

use Karsjen\StatelessQueue\Tests\TestCase;
use RuntimeException;

/**
 * Adapter resolution and default selection through the service container.
 *
 * Resolution is performed by {@see \Karsjen\StatelessQueue\StatelessQueueServiceProvider}, which binds
 * the OutboundAdapter contract to whichever adapter `stateless-queue.default` names.
 *
 * Unit boundary:
 * - Inside: reading the default adapter name from config and mapping names to adapter instances.
 * - Outside: real publish or transport behaviour (covered by the adapter unit tests and the E2E suites).
 *
 * Why this matters:
 * - If the wrong config key is read or the fallback breaks, *every* dispatch path fails even when the
 *   individual adapters are correct.
 *
 * Validates:
 * - The default adapter is read from `stateless-queue.default` and falls back to `null` when empty.
 * - Each known adapter name resolves to the expected concrete adapter instance.
 * - An unknown adapter name throws `RuntimeException`.
 *
 * Does not validate:
 * - Inbound adapter selection for a request (covered by `Unit/Runtime/ProviderRegistryTest`).
 */
class StatelessQueueManagerTest extends TestCase
{
    /**
     * Proves the manager’s default-adapter lookup is a pure config contract.
     *
     * Validates:
     * - The package default falls back to `null` (safe default).
     * - Overriding `stateless-queue.default` is reflected by `getDefaultAdapter()`.
     *
     * Out of scope:
     * - Adapter construction and adapter behavior (this test only checks the selected name).
     */
    public function test_get_default_adapter_honours_config_and_falls_back_to_null(): void
    {
        config()->set('stateless-queue.default', null);
        $default = app()->make(OutboundAdapter::class);
        $this->assertInstanceOf(NullAdapter::class, $default);

        config()->set('stateless-queue.default', 'google');
        $google = app()->make(OutboundAdapter::class);
        $this->assertInstanceOf(GooglePubSubAdapter::class, $google);
    }

    public function test_base_test_setup_uses_default_adapter_key(): void
    {
        $this->assertSame('null', config('stateless-queue.default'));
    }

    /**
     * Verifies `adapter(<name>)` returns concrete adapter instances for known names.
     *
     * Validates:
     * - `adapter('null')` returns `NullAdapter`.
     * - `adapter('google')` returns `GooglePubSubAdapter`.
     * - `adapter('aws')` returns `AwsSnsAdapter`.
     *
     * Test setup:
     * - Provides minimal per-adapter config to allow constructors to initialise without performing IO.
     *
     * Out of scope:
     * - Any publish behavior (handled by adapter unit tests and E2E tests).
     */
    public function test_adapter_method_returns_expected_adapter_instances(): void
    {
        // Provide minimal config so constructors can initialise
        config()->set('stateless-queue.connections.google', [
            'project_id' => 'test-project',
            'key_file' => __FILE__,
        ]);

        config()->set('stateless-queue.connections.aws', [
            'region' => 'us-east-1',
            'account_id' => '000000000000',
        ]);

        config()->set('stateless-queue.default', 'null');
        $this->assertInstanceOf(NullAdapter::class, app()->make(OutboundAdapter::class));

        config()->set('stateless-queue.default', 'google');
        $this->assertInstanceOf(GooglePubSubAdapter::class, app()->make(OutboundAdapter::class));

        config()->set('stateless-queue.default', 'aws');
        $this->assertInstanceOf(AwsSnsAdapter::class, app()->make(OutboundAdapter::class));
    }

    /**
     * Ensures unknown adapter names fail fast with a programmer-facing exception.
     *
     * Validates:
     * - Resolving the OutboundAdapter contract with an unknown `default` throws `RuntimeException`.
     *
     * Out of scope:
     * - Any container resolution fallbacks or dynamic registration (this is the explicit failure path).
     */
    public function test_unknown_adapter_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported stateless queue adapter [does-not-exist]');
        config()->set('stateless-queue.default', 'does-not-exist');
        app()->make(OutboundAdapter::class);
    }
}


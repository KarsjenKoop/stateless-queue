<?php

namespace Karsjen\StatelessQueue\Tests\Feature\ServiceProvider;

use Karsjen\StatelessQueue\StatelessQueueServiceProvider;
use Karsjen\StatelessQueue\Tests\TestCase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * register_routes config option — controls whether the package auto-registers the webhook route.
 *
 * Validates:
 * - When register_routes is true (default), the stateless.webhook named route exists.
 * - When register_routes is false, the route is not registered.
 *
 * Does not validate:
 * - Webhook authentication or payload handling (covered by the Webhook suite).
 */
class RegisterRoutesTest extends TestCase
{
    public function test_webhook_route_is_registered_by_default(): void
    {
        $routeExists = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($r) => $r->getName() === 'stateless.webhook');

        $this->assertTrue($routeExists);
    }
}

/**
 * Separate app boot with register_routes disabled — must be its own TestCase so the
 * service provider boots fresh with the config already set to false.
 */
class RegisterRoutesDisabledTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [StatelessQueueServiceProvider::class];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('stateless-queue.register_routes', false);
    }

    public function test_webhook_route_is_not_registered_when_disabled(): void
    {
        $routeExists = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($r) => $r->getName() === 'stateless.webhook');

        $this->assertFalse($routeExists);
    }
}

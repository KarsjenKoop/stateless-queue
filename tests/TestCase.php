<?php

namespace Karsjen\StatelessQueue\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Karsjen\StatelessQueue\StatelessQueueServiceProvider;

/**
 * Base test case for every non-E2E test in the suite.
 *
 * Boots a Testbench application with the package's service provider registered and a configuration
 * that keeps tests hermetic:
 * - `default` is `null`, so nothing is ever published to a real provider.
 * - `default_topic` is `test-topic`, giving topic-resolution tests a distinguishable fallback.
 * - `allowed_jobs` permits `Karsjen\StatelessQueue\Tests\*`, so webhook tests can execute fixtures.
 *
 * Tests that need an emulator should extend {@see \Karsjen\StatelessQueue\Tests\E2E\GooglePubSubE2ETestCase}
 * or {@see \Karsjen\StatelessQueue\Tests\E2E\AwsSnsE2ETestCase} instead.
 */
class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            StatelessQueueServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Set default config for testing
        config()->set('stateless-queue.default', 'null');
        config()->set('stateless-queue.default_topic', 'test-topic');
        // Allow package test job classes for webhook decoder tests
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\StatelessQueue\Tests\*']);
    }
}
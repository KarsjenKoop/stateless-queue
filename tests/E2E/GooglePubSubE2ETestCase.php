<?php

namespace Karsjen\StatelessQueue\Tests\E2E;

use Karsjen\StatelessQueue\Tests\Support\GooglePubSubTestHelper;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * E2E base case for tests that require the Google Pub/Sub emulator.
 *
 * Sets up:
 * - Default adapter `google` and emulator project config.
 * - `allow_local_unverified=true` to reduce auth friction in local/emulator traffic.
 */
abstract class GooglePubSubE2ETestCase extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $projectId = env('GOOGLE_CLOUD_PROJECT', 'test-project');

        // Ensure the middleware local-bypass path is active during E2E tests
        $app['config']->set('app.env', 'local');

        // Force the stateless queue to use the Google adapter for E2E tests
        $app['config']->set('stateless-queue.default', 'google');
        $app['config']->set('stateless-queue.connections.google.project_id', $projectId);

        // Use a distinct default topic for E2E routing tests
        $app['config']->set('stateless-queue.default_topic', 'stateless-queue-e2e-topic-default');

        // Make it easy to bypass signature verification for local/emulator traffic
        $app['config']->set('stateless-queue.allow_local_unverified', true);
    }

    protected function requirePubSubEmulator(): void
    {
        if (!GooglePubSubTestHelper::isEmulatorAvailable()) {
            $this->markTestSkipped('Pub/Sub emulator is not reachable on PUBSUB_EMULATOR_HOST.');
        }
    }

    protected function pubSubHelper(): GooglePubSubTestHelper
    {
        return new GooglePubSubTestHelper();
    }
}


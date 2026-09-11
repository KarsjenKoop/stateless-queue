<?php

namespace Karsjen\StatelessQueue\Tests\E2E\Google;

use Karsjen\StatelessQueue\Tests\E2E\GooglePubSubE2ETestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GoogleE2ETestJob;
use PHPUnit\Framework\Attributes\Group;

#[Group('e2e')]
/**
 * E2E: Google adapter publishes into the Pub/Sub emulator.
 *
 * Context:
 * - This test requires a reachable Pub/Sub emulator (`PUBSUB_EMULATOR_HOST`).
 * - It validates the adapter-to-emulator publish path only (no HTTP webhook involved).
 *
 * Validates:
 * - Publishing through the Google adapter results in a message that can be pulled from the emulator.
 *
 * Does not validate:
 * - Webhook HTTP decoding/processing (covered by `GoogleHttpRoundTripE2ETest`).
 */
class GoogleAdapterE2ETest extends GooglePubSubE2ETestCase
{
    /**
     * Publishes a job to the Pub/Sub emulator and asserts the message can be pulled and decoded.
     *
     * Validates:
     * - A `push()` call results in a message in the expected topic.
     * - The pulled message JSON contains `job_class`, `topic`, and an array `payload`.
     *
     * Out of scope:
     * - Webhook delivery/execution.
     * - Google authentication (emulator mode).
     */
    public function test_it_publishes_and_can_be_pulled_from_emulator(): void
    {
        // Given
        $this->requirePubSubEmulator();

        $helper = $this->pubSubHelper();

        $topic = 'stateless-queue-e2e-topic-driver';
        $subscriptionName = 'stateless-queue-e2e-sub-driver';

        $helper->ensureSubscription($subscriptionName, $topic);

        GoogleE2ETestJob::$handled = false;
        GoogleE2ETestJob::$handledMessage = null;

        // When
        $job = new GoogleE2ETestJob('Hello E2E');
        $job->statelessTopic = $topic;
        $job->push();

        $message = $helper->pullSingleMessage($subscriptionName, 10, 1);

        // Then
        $this->assertNotNull($message, 'Expected a message to be available from the Pub/Sub emulator.');

        $decoded = json_decode($message->data(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(GoogleE2ETestJob::class, $decoded['job_class'] ?? null);
        $this->assertSame($topic, $decoded['topic'] ?? null);

        $payload = $decoded['payload'] ?? null;

        $this->assertIsArray($payload, 'Expected an array payload inside the message data.');
        $this->assertArrayHasKey('message', $payload);
        $this->assertSame('Hello E2E', $payload['message']);

        // And: validate the job can be instantiated and executed as the controller would
        $job = app()->make(GoogleE2ETestJob::class, $payload);
        $job->handle();

        $this->assertTrue(GoogleE2ETestJob::$handled);
        $this->assertSame('Hello E2E', GoogleE2ETestJob::$handledMessage);
    }
}


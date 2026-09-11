<?php

namespace Karsjen\StatelessQueue\Tests\E2E\Google;

use Karsjen\StatelessQueue\Tests\E2E\GooglePubSubE2ETestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\CustomTopicStatelessJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\SnakeCaseTopicStatelessJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\DefaultTopicStatelessJob;
use PHPUnit\Framework\Attributes\Group;

#[Group('e2e')]
/**
 * E2E: topic selection and routing (Google Pub/Sub emulator).
 *
 * Context:
 * - The topic name is a routing key. This suite verifies which topic receives a message
 *   based on job properties and config defaults.
 * - Uses the Pub/Sub emulator to verify routing by actually pulling from per-topic subscriptions.
 *
 * Validates:
 * - `statelessTopic` / `stateless_topic` job properties influence which topic receives the message.
 * - When no job topic is defined, config `stateless-queue.default_topic` is used.
 *
 * Does not validate:
 * - Webhook processing (HTTP) of messages (covered by `GoogleHttpRoundTripE2ETest`).
 */
class GoogleTopicRoutingE2ETest extends GooglePubSubE2ETestCase
{
    /**
     * Routes a job to a custom topic via `statelessTopic` and verifies the message appears there.
     *
     * Validates:
     * - The decoded message pulled from the subscription has `topic` equal to the configured topic name.
     *
     * Out of scope:
     * - HTTP webhook processing (this is publish/pull only).
     */
    public function test_job_with_statelessTopic_property_uses_that_topic(): void
    {
        // Given
        $this->requirePubSubEmulator();

        $helper = $this->pubSubHelper();

        $topic = 'stateless-queue-e2e-topic-custom';
        $subscriptionName = 'stateless-queue-e2e-sub-custom';

        $helper->ensureSubscription($subscriptionName, $topic);

        // When
        $job = new CustomTopicStatelessJob('Custom topic');
        $job->push();

        $message = $helper->pullSingleMessage($subscriptionName, 10, 1);

        // Then
        $this->assertNotNull($message, 'Expected a message for the custom-topic job.');

        $decoded = json_decode($message->data(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(CustomTopicStatelessJob::class, $decoded['job_class'] ?? null);
        $this->assertSame($topic, $decoded['topic'] ?? null);
    }

    /**
     * Routes a job to a custom topic via snake_case `stateless_topic` and verifies the message appears there.
     *
     * Validates:
     * - Both naming conventions are supported for topic selection.
     */
    public function test_job_with_snake_case_stateless_topic_property_uses_that_topic(): void
    {
        // Given
        $this->requirePubSubEmulator();

        $helper = $this->pubSubHelper();

        $topic = 'stateless-queue-e2e-topic-snake';
        $subscriptionName = 'stateless-queue-e2e-sub-snake';

        $helper->ensureSubscription($subscriptionName, $topic);

        // When
        $job = new SnakeCaseTopicStatelessJob('Snake topic');
        $job->push();

        $message = $helper->pullSingleMessage($subscriptionName, 10, 1);

        // Then
        $this->assertNotNull($message, 'Expected a message for the snake_case topic job.');

        $decoded = json_decode($message->data(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(SnakeCaseTopicStatelessJob::class, $decoded['job_class'] ?? null);
        $this->assertSame($topic, $decoded['topic'] ?? null);
    }

    /**
     * Routes a job without an explicit topic to the config default topic.
     *
     * Validates:
     * - When no job property is present, the decoded message `topic` equals `stateless-queue.default_topic`.
     */
    public function test_job_without_topic_property_uses_default_topic_from_config(): void
    {
        // Given
        $this->requirePubSubEmulator();

        $helper = $this->pubSubHelper();

        $defaultTopic = config('stateless-queue.default_topic');
        $subscriptionName = 'stateless-queue-e2e-sub-default';

        $helper->ensureSubscription($subscriptionName, $defaultTopic);

        // When
        $job = new DefaultTopicStatelessJob('Default topic');
        $job->push();

        $message = $helper->pullSingleMessage($subscriptionName, 10, 1);

        // Then
        $this->assertNotNull($message, 'Expected a message for the default-topic job.');

        $decoded = json_decode($message->data(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(DefaultTopicStatelessJob::class, $decoded['job_class'] ?? null);
        $this->assertSame($defaultTopic, $decoded['topic'] ?? null);
    }
}


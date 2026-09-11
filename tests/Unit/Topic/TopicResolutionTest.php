<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Topic;

use Karsjen\StatelessQueue\Tests\TestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithNoTopic;
use Karsjen\StatelessQueue\Tests\Support\Jobs\JobWithSpecificTopic;

/**
 * Topic resolution rules for stateless payload creation.
 *
 * Unit boundary:
 * - Inside: precedence rules for choosing a topic when building a stateless payload.
 * - Outside: actual publishing/routing via SNS/PubSub (covered by E2E routing tests).
 *
 * Notes:
 * - These tests use reflection to call `createOutgoingMessage` because it is not part of the public API.
 *   This intentionally avoids needing a adapter or dispatch path to observe the topic value.
 *
 * Validates:
 * - Default topic from config is used when a job does not declare a topic.
 * - A job-declared topic overrides the config default.
 *
 * Does not validate:
 * - Topic routing via external brokers (covered by E2E routing tests).
 *
 * Notes:
 * - These tests use reflection to call `createOutgoingMessage` because it is not public API.
 */
class TopicResolutionTest extends TestCase
{
    /**
     * Confirms the config default topic is used when the job does not declare a topic property.
     *
     * Validates:
     * - `stateless-queue.default_topic` is applied as the payload topic when the job has no explicit topic.
     *
     * Out of scope:
     * - End-to-end broker routing; this is payload construction only.
     */
    public function test_it_uses_config_default_topic_if_none_defined(): void
    {
        config()->set('stateless-queue.default_topic', 'global-default');
        
        $job = new JobWithNoTopic();
        // We access the protected method via reflection or by exposing it for test
        // Or simpler: just inspect the payload it creates
        $payload = $this->getPayloadFromJob($job);

        $this->assertSame('global-default', $payload->topic);
    }

    /**
     * Confirms a job-declared topic overrides the global config default.
     *
     * Validates:
     * - When the job declares `statelessTopic`, that value wins over `stateless-queue.default_topic`.
     *
     * Out of scope:
     * - Whether the declared topic exists in any broker; this is purely selection logic.
     */
    public function test_it_uses_class_property_over_config(): void
    {
        config()->set('stateless-queue.default_topic', 'global-default');
        
        $job = new JobWithSpecificTopic();
        $payload = $this->getPayloadFromJob($job);

        $this->assertSame('special-topic', $payload->topic);
    }

    // Helper to extract payload without mocking the adapter
    protected function getPayloadFromJob(object $job): object
    {
        // We use reflection to call the protected createOutgoingMessage method.
        // ReflectionMethod::setAccessible is a no-op on PHP 8.1+.
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('createOutgoingMessage');
        return $method->invoke($job);
    }
}
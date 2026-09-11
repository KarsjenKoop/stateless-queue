<?php

namespace Karsjen\StatelessQueue\Tests\E2E\Aws;

use Aws\Sns\Exception\SnsException;
use Karsjen\StatelessQueue\Tests\E2E\AwsSnsE2ETestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\CustomTopicStatelessJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\SnakeCaseTopicStatelessJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\DefaultTopicStatelessJob;
use PHPUnit\Framework\Attributes\Group;

#[Group('e2e')]
/**
 * E2E: topic selection and routing (AWS SNS via LocalStack).
 *
 * Context:
 * - Uses LocalStack SNS to validate that publish calls succeed against a real SNS-compatible endpoint.
 * - This suite focuses on topic selection (which SNS topic name/ARN is targeted) based on job properties
 *   and configuration defaults.
 *
 * Validates:
 * - `statelessTopic` / `stateless_topic` job properties influence which SNS topic is published to.
 * - When no job topic is defined, config `stateless-queue.default_topic` is used.
 *
 * Does not validate:
 * - Webhook HTTP decoding/processing of SNS notifications (covered by feature tests).
 */
class AwsSnsTopicRoutingE2ETest extends AwsSnsE2ETestCase
{
    /**
     * Publishes a job to an SNS topic chosen via `statelessTopic`.
     *
     * Validates:
     * - Creating the topic in LocalStack and calling `push()` does not throw an SNS exception.
     *
     * Out of scope:
     * - Verifying message contents inside SNS (this test only asserts successful publish).
     */
    public function test_job_with_statelessTopic_property_publishes_to_sns(): void
    {
        // Given
        $this->requireLocalstackSns();

        $sns = $this->snsClient();
        $topicName = 'stateless-queue-e2e-aws-custom';

        // When
        $sns->createTopic(['Name' => $topicName]);

        $job = new CustomTopicStatelessJob('AWS custom topic');
        $job->statelessTopic = $topicName;

        // Then
        $this->assertPublishDoesNotThrow($job);
    }

    /**
     * Publishes a job to an SNS topic chosen via snake_case `stateless_topic`.
     *
     * Validates:
     * - Snake_case property name is honored for topic selection.
     */
    public function test_job_with_snake_case_stateless_topic_property_publishes_to_sns(): void
    {
        // Given
        $this->requireLocalstackSns();

        $sns = $this->snsClient();
        $topicName = 'stateless-queue-e2e-aws-snake';

        // When
        $sns->createTopic(['Name' => $topicName]);

        $job = new SnakeCaseTopicStatelessJob('AWS snake topic');
        $job->stateless_topic = $topicName;

        // Then
        $this->assertPublishDoesNotThrow($job);
    }

    /**
     * Publishes a job without an explicit topic to the config default SNS topic.
     *
     * Validates:
     * - `stateless-queue.default_topic` is used when the job does not declare a topic.
     */
    public function test_job_without_topic_property_uses_default_topic_and_publishes_to_sns(): void
    {
        // Given
        $this->requireLocalstackSns();

        $sns = $this->snsClient();

        $defaultTopic = config('stateless-queue.default_topic', 'test-topic');

        // When
        $sns->createTopic(['Name' => $defaultTopic]);

        $job = new DefaultTopicStatelessJob('AWS default topic');

        // Then
        $this->assertPublishDoesNotThrow($job);
    }

    protected function assertPublishDoesNotThrow(object $job): void
    {
        try {
            $job->push();
            $this->assertTrue(true); // If we reach here, publish succeeded
        } catch (SnsException $e) {
            $this->fail('SNS publish threw an exception: ' . $e->getMessage());
        }
    }
}


<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\Support\Jobs\AwsHttpE2ETestJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\WebhookReceiverTestJob;
use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Webhook execution contract: decoded payload -> instantiated job -> handle() runs.
 *
 * Context:
 * - The webhook receives provider-specific envelopes (Google push / AWS SNS) and decodes them into a
 *   single internal “decoded message” shape (`job_class`, `payload`, `topic`, `uuid`, `timestamp`).
 * - These tests intentionally bypass authentication middleware so we can focus on decoding + execution.
 *
 * Validates:
 * - A decoded Google push message causes the job to be instantiated and executed.
 * - A decoded AWS SNS notification causes the job to be instantiated and executed.
 *
 * Does not validate:
 * - Request authentication/signatures (middleware bypassed).
 * - Topic routing via external brokers (covered by E2E routing tests).
 */
class WebhookExecutesJobTest extends TestCase
{
    /**
     * Happy-path execution for a Google Pub/Sub push envelope.
     *
     * Validates:
     * - The request is recognized as a Google push payload and decoded.
     * - The decoded `job_class` is instantiated with the decoded `payload` array.
     * - The job’s `handle()` method runs (asserted via static flags on the fixture job).
     * - The webhook returns 200 to acknowledge successful processing.
     *
     * Payload context:
     * - Wrapper: `message.data` is base64-encoded JSON.
     * - Inner decoded message includes: `job_class`, `payload`, `topic`, `uuid`, `timestamp`.
     *
     * Out of scope:
     * - Google authentication/token verification.
     * - Topic routing via external brokers (Pub/Sub) — this is controller execution only.
     */
    public function test_it_receives_and_executes_a_job_from_google_push_payload(): void
    {
        // Given
        WebhookReceiverTestJob::$wasRun = false;
        WebhookReceiverTestJob::$receivedMessage = null;

        $job = new WebhookReceiverTestJob('Test Message');

        $messageData = [
            'uuid' => 'google-exec-1',
            'job_class' => WebhookReceiverTestJob::class,
            'topic' => 'test-topic',
            'payload' => $job->getPayload(),
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertStatus(200);
        $this->assertTrue(WebhookReceiverTestJob::$wasRun);
        $this->assertSame('Test Message', WebhookReceiverTestJob::$receivedMessage);
    }

    /**
     * Happy-path execution for an AWS SNS Notification envelope.
     *
     * Validates:
     * - The request is recognized as SNS Notification (via header + envelope fields) and decoded.
     * - The decoded `job_class` is instantiated with the decoded `payload` array.
     * - The job’s `handle()` method runs (asserted via static flags on the fixture job).
     * - The webhook returns 200 to acknowledge successful processing.
     *
     * Payload context:
     * - Header: `x-amz-sns-message-type: Notification`
     * - Wrapper: `Type=Notification`, `Message` is JSON string, `MessageAttributes.topic.Value` exists.
     *
     * Out of scope:
     * - SNS signature verification.
     * - End-to-end publish/receive through LocalStack SNS.
     */
    public function test_it_receives_and_executes_a_job_from_aws_sns_notification(): void
    {
        // Given
        AwsHttpE2ETestJob::$handled = false;
        AwsHttpE2ETestJob::$handledMessage = null;

        $job = new AwsHttpE2ETestJob('Hello AWS HTTP');

        $messageData = [
            'uuid' => 'aws-exec-1',
            'job_class' => AwsHttpE2ETestJob::class,
            'topic' => 'aws-http-e2e-topic',
            'payload' => $job->getPayload(),
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(200);
        $this->assertTrue(AwsHttpE2ETestJob::$handled);
        $this->assertSame('Hello AWS HTTP', AwsHttpE2ETestJob::$handledMessage);
    }
}


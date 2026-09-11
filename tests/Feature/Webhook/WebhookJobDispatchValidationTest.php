<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\Support\Jobs\FailingWebhookJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\WebhookReceiverTestJob;
use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Webhook decoded-message validation and job dispatch rules.
 *
 * Validates:
 * - Decoded messages must contain required fields (`job_class`, `payload`, etc.) and payload must be an array.
 * - Non-existent job classes are rejected deterministically.
 * - Corrupted payloads are rejected deterministically.
 * - Exceptions thrown by the executed job surface as 500 from the webhook controller.
 *
 * Does not validate:
 * - Authentication/gating decisions (covered by `WebhookAuthenticationTest`).
 * - Allowlist pattern matching (covered by `WebhookAllowlistTest`).
 */
class WebhookJobDispatchValidationTest extends TestCase
{
    /**
     * Requires `job_class` to be present in the decoded message (Google push).
     *
     * Validates:
     * - Missing `job_class` is treated as an invalid decoded message shape.
     * - The webhook returns 422 with a stable "Invalid payload structure" error.
     *
     * Out of scope:
     * - Allowlist behavior (this test ensures structure fails before policy is relevant).
     */
    public function test_missing_job_class_results_in_422_invalid_payload_structure_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        $messageData = [
            'uuid' => 'missing-job-class',
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertUnprocessable()
            ->assertJsonFragment(['error' => 'Invalid payload structure']);
    }

    /**
     * Requires `job_class` to be present in the decoded message (AWS SNS Notification).
     *
     * Validates:
     * - Same structural requirement applies to SNS-decoded messages.
     */
    public function test_missing_job_class_results_in_422_invalid_payload_structure_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        $messageData = [
            'uuid' => 'missing-job-class-aws',
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertUnprocessable()
            ->assertJsonFragment(['error' => 'Invalid payload structure']);
    }

    /**
     * Requires the decoded `payload` to be an array (Google push).
     *
     * Validates:
     * - A scalar/non-array payload is rejected as "Invalid payload structure".
     */
    public function test_non_array_payload_results_in_422_invalid_payload_structure_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        $messageData = [
            'uuid' => 'scalar-payload',
            'job_class' => WebhookReceiverTestJob::class,
            'topic' => 'test-topic',
            'payload' => 'not-an-array',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertUnprocessable()
            ->assertJsonFragment(['error' => 'Invalid payload structure']);
    }

    /**
     * Requires the decoded `payload` to be an array (AWS SNS Notification).
     *
     * Validates:
     * - Type/shape validation is enforced equally for SNS-decoded messages.
     */
    public function test_non_array_payload_results_in_422_invalid_payload_structure_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        $messageData = [
            'uuid' => 'scalar-payload-aws',
            'job_class' => WebhookReceiverTestJob::class,
            'topic' => 'test-topic',
            'payload' => 'not-an-array',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertUnprocessable()
            ->assertJsonFragment(['error' => 'Invalid payload structure']);
    }

    /**
     * Rejects decoded messages that reference a job class that does not exist (Google push).
     *
     * Validates:
     * - An allowlisted but non-existent `job_class` yields a deterministic 500 (a deployment fault,
     *   distinct from the 403 an unallowlisted class gets).
     *
     * Out of scope:
     * - Exact error payload; only status is pinned to avoid coupling to message text.
     */
    public function test_nonexistent_job_class_returns_500_for_google_push(): void
    {
        // Given — the class must be allowlisted, otherwise the allowlist denies it first (403) and the
        // class_exists() branch this test targets is never reached.
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

        $messageData = [
            'uuid' => 'ghost-1',
            'job_class' => 'App\\Jobs\\GhostJob',
            'topic' => 'test-topic',
            'payload' => ['dummy' => true],
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertStatus(500);
    }

    /**
     * Rejects decoded messages that reference a job class that does not exist (AWS SNS Notification).
     */
    public function test_nonexistent_job_class_returns_500_for_aws_sns_notification(): void
    {
        // Given — the class must be allowlisted, otherwise the allowlist denies it first (403) and the
        // class_exists() branch this test targets is never reached.
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

        $messageData = [
            'uuid' => 'ghost-aws',
            'job_class' => 'App\\Jobs\\GhostJob',
            'topic' => 'test-topic',
            'payload' => ['dummy' => true],
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(500);
    }

    /**
     * Surfaces corrupted/undecodable job payload material as an unprocessable payload (Google push).
     *
     * Validates:
     * - The webhook does not silently acknowledge a decoded message whose job payload cannot be interpreted.
     */
    public function test_corrupted_payload_returns_422_for_google_push(): void
    {
        // Given
        $messageData = [
            'uuid' => 'corrupt-1',
            'job_class' => WebhookReceiverTestJob::class,
            'topic' => 'test-topic',
            'payload' => 'THIS_IS_NOT_BASE64_PHP_OBJECT',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertUnprocessable();
    }

    /**
     * Surfaces corrupted/undecodable job payload material as an unprocessable payload (AWS SNS Notification).
     */
    public function test_corrupted_payload_returns_422_for_aws_sns_notification(): void
    {
        // Given
        $messageData = [
            'uuid' => 'corrupt-aws',
            'job_class' => WebhookReceiverTestJob::class,
            'topic' => 'test-topic',
            'payload' => 'THIS_IS_NOT_BASE64_PHP_OBJECT',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertUnprocessable();
    }

    /**
     * Ensures exceptions thrown during job execution are surfaced as HTTP 500 (Google push).
     *
     * Validates:
     * - The job is actually invoked (asserted via side-effect flag).
     * - The thrown exception is surfaced as a 500 error response with an error fragment.
     *
     * Out of scope:
     * - Logging/observability behavior for the exception.
     */
    public function test_job_exception_bubbles_up_as_500_from_controller_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        FailingWebhookJob::$handled = false;

        $job = new FailingWebhookJob('boom');
        $messageData = [
            'uuid' => 'failing-job',
            'job_class' => FailingWebhookJob::class,
            'topic' => 'test-topic',
            'payload' => $job->getPayload(),
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then
        $response->assertStatus(500)
            ->assertJsonFragment(['error' => 'Job execution failed']);
        $this->assertTrue(FailingWebhookJob::$handled);
    }

    /**
     * Ensures exceptions thrown during job execution are surfaced as HTTP 500 (AWS SNS Notification).
     */
    public function test_job_exception_bubbles_up_as_500_from_controller_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        FailingWebhookJob::$handled = false;

        $job = new FailingWebhookJob('boom-aws');
        $messageData = [
            'uuid' => 'failing-job-aws',
            'job_class' => FailingWebhookJob::class,
            'topic' => 'test-topic',
            'payload' => $job->getPayload(),
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(500)
            ->assertJsonFragment(['error' => 'Job execution failed']);
        $this->assertTrue(FailingWebhookJob::$handled);
    }
}


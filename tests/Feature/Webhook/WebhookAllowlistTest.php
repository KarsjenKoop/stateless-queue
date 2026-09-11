<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\TestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\FailingWebhookJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GoogleE2ETestJob;
use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;

/**
 * Webhook job allowlist enforcement (allowed job class patterns).
 *
 * Validates:
 * - Requests that decode into a job are rejected when the decoded job class does not match `allowed_jobs`.
 * - Empty `allowed_jobs` rejects all decoded jobs.
 * - Matching allowlist patterns allow execution of the decoded job.
 *
 * - A rejected class answers 403, not 500, so the provider does not retry it.
 *
 * Does not validate:
 * - Request authentication (covered by `WebhookAuthenticationTest`).
 * - Source detection / decoder robustness (covered by parsing/validation tests).
 */
class WebhookAllowlistTest extends TestCase
{
    /**
     * Rejects a decoded Google push job when its class does not match `stateless-queue.allowed_jobs`.
     *
     * Context:
     * - The webhook can decode a Google Pub/Sub push envelope into an internal decoded message containing
     *   `job_class` and `payload`.
     * - `allowed_jobs` is a safety boundary: decoded jobs must match a configured pattern to be executable.
     *
     * Validates:
     * - Non-matching allowlist patterns result in a 403 response with an error indicating the class is not allowed.
     *
     * Out of scope:
     * - Authentication/token verification (middleware bypassed).
     * - Payload parsing robustness (covered by parsing tests).
     */
    public function test_job_class_not_in_allowed_list_is_rejected_with_403_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

        $job = new GoogleE2ETestJob('x');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => '1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData, ['topic' => 'test']));

        // Then
        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'Job class not allowed']);
    }

    /**
     * Rejects a decoded AWS SNS Notification job when its class does not match `stateless-queue.allowed_jobs`.
     *
     * Validates:
     * - Allowlist enforcement is source-agnostic (AWS SNS decoded jobs are checked the same way as Google).
     *
     * Payload context:
     * - SNS envelope is provided via `WebhookPayloadBuilder::awsSnsNotification()` and the SNS header.
     *
     * Out of scope:
     * - SNS signature verification (middleware bypassed).
     */
    public function test_job_class_not_in_allowed_list_is_rejected_with_403_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

        $job = new GoogleE2ETestJob('x');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => 'aws-1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'Job class not allowed']);
    }

    /**
     * Ensures an empty allowlist denies all decoded jobs (Google push).
     *
     * Validates:
     * - With `allowed_jobs=[]`, even a valid decoded job is rejected (deny-by-default).
     *
     * Out of scope:
     * - Whether the job would otherwise be executable (policy rejection happens first).
     */
    public function test_empty_allowed_jobs_rejects_every_job_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', []);

        $job = new GoogleE2ETestJob('x');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => '1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData, ['topic' => 'test']));

        // Then
        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'Job class not allowed']);
    }

    /**
     * Ensures an empty allowlist denies all decoded jobs (AWS SNS Notification).
     *
     * Validates:
     * - Same deny-by-default behavior applies to SNS-decoded messages.
     */
    public function test_empty_allowed_jobs_rejects_every_job_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', []);

        $job = new GoogleE2ETestJob('x');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => 'aws-2',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'Job class not allowed']);
    }

    /**
     * Allows and executes a decoded job when its class matches the allowlist (Google push).
     *
     * Validates:
     * - Matching wildcard pattern permits execution.
     * - Response is 200 with success payload.
     * - Job `handle()` ran (asserted via static flags on the job fixture).
     *
     * Out of scope:
     * - Deep validation of payload contents (only correctness required to instantiate/run is assumed).
     */
    public function test_allowed_pattern_accepts_matching_job_and_runs_it_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\Support\\Jobs\\*']);

        GoogleE2ETestJob::$handled = false;
        GoogleE2ETestJob::$handledMessage = null;

        $job = new GoogleE2ETestJob('allowlist-ok');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => '1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData, ['topic' => 'test']));

        // Then
        $response->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertTrue(GoogleE2ETestJob::$handled);
        $this->assertSame('allowlist-ok', GoogleE2ETestJob::$handledMessage);
    }

    /**
     * Allows and executes a decoded job when its class matches the allowlist (AWS SNS Notification).
     *
     * Validates:
     * - Allowlist acceptance and execution semantics match Google push behavior.
     */
    public function test_allowed_pattern_accepts_matching_job_and_runs_it_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\Support\\Jobs\\*']);

        GoogleE2ETestJob::$handled = false;
        GoogleE2ETestJob::$handledMessage = null;

        $job = new GoogleE2ETestJob('allowlist-ok-aws');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload' => $job->getPayload(),
            'topic' => 'test',
            'uuid' => 'aws-3',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertTrue(GoogleE2ETestJob::$handled);
        $this->assertSame('allowlist-ok-aws', GoogleE2ETestJob::$handledMessage);
    }

    /**
     * Ensures payload-structure validation occurs even if allowlist patterns match (Google push).
     *
     * Validates:
     * - A class that matches `allowed_jobs` but is not a valid stateless job contract is rejected with
     *   a clear ShouldStatelessQueue contract error.
     *
     * Out of scope:
     * - The exact internal validation checks; only observable behavior is pinned.
     */
    public function test_invalid_payload_structure_is_rejected_even_if_allowlist_matches_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\Feature\\*']);

        // NotAJob is in allowlist but does not implement ShouldStatelessQueue.
        $messageData = [
            'job_class' => NotAJob::class,
            'payload' => [],
            'topic' => 'test',
            'uuid' => '1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData, ['topic' => 'test']));

        // Then
        $response->assertStatus(500)
            ->assertJsonFragment([
                'error' => 'Job execution failed',
            ]);
    }

    /**
     * Ensures payload-structure validation occurs even if allowlist patterns match (AWS SNS Notification).
     *
     * Validates:
     * - Structural/job-contract validation is applied consistently across SNS-decoded messages.
     */
    public function test_invalid_payload_structure_is_rejected_even_if_allowlist_matches_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\Feature\\*']);

        $messageData = [
            'job_class' => NotAJob::class,
            'payload' => [],
            'topic' => 'test',
            'uuid' => 'aws-4',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::awsSnsNotification($messageData));

        // Then
        $response->assertStatus(500)
            ->assertJsonFragment([
                'error' => 'Job execution failed',
            ]);
    }
}

final class NotAJob
{

    /**
     * The allowlist rejection status must be distinguishable from an execution failure.
     *
     * Both used to answer 500. That conflated an authorisation decision with a transient fault, and
     * 5xx is precisely what tells Pub/Sub and SNS to redeliver — so a caller probing the webhook for
     * executable class names had their payload replayed until the topic's dead-letter policy gave up,
     * while the operator saw a stream of "server error" alerts for what was a working denial.
     *
     * Validates:
     * - A disallowed class answers 403 (do not retry).
     * - A permitted class that throws during handle() still answers 500 (do retry).
     * - The two are therefore distinguishable by status alone.
     */
    public function test_disallowed_class_answers_403_while_execution_failure_answers_500(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);

        $envelope = function (string $jobClass, array $payload): array {
            return [
                'uuid' => 'retry-semantics',
                'job_class' => $jobClass,
                'topic' => 'test-topic',
                'payload' => $payload,
                'timestamp' => time(),
            ];
        };

        // When — the class is not allowlisted.
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);
        $rejected = $this->withoutMiddleware()->postJson(
            route('stateless.webhook'),
            WebhookPayloadBuilder::googlePush($envelope(FailingWebhookJob::class, ['message' => 'x'])),
        );

        // When — the same class is allowlisted, and then throws from handle().
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);
        FailingWebhookJob::$handled = false;
        $failed = $this->withoutMiddleware()->postJson(
            route('stateless.webhook'),
            WebhookPayloadBuilder::googlePush($envelope(FailingWebhookJob::class, ['message' => 'x'])),
        );

        // Then
        $rejected->assertStatus(403);
        $failed->assertStatus(500);
        $this->assertNotSame($rejected->getStatusCode(), $failed->getStatusCode());
    }

    /**
     * A rejected class must not reach the job at all.
     *
     * Validates:
     * - The allowlist check runs before instantiation, so handle() is never invoked for a class the
     *   allowlist denies. The 403 is a real denial, not a job that ran and then reported failure.
     */
    public function test_disallowed_class_is_never_instantiated_or_executed(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);
        FailingWebhookJob::$handled = false;

        // When
        $response = $this->withoutMiddleware()->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush([
            'uuid' => 'never-runs',
            'job_class' => FailingWebhookJob::class,
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ]));

        // Then
        $response->assertStatus(403);
        $this->assertFalse(
            FailingWebhookJob::$handled,
            'A job denied by the allowlist must never be instantiated or executed.',
        );
    }
}

<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Runtime;

use Karsjen\StatelessQueue\Contracts\JobExecutor;
use Karsjen\StatelessQueue\Messages\IncomingJobMessage;
use Karsjen\StatelessQueue\Runtime\JobRunner;
use Karsjen\StatelessQueue\Tests\TestCase;
use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GoogleE2ETestJob;

/**
 * JobExecutor contract binding — default resolution and custom swap.
 *
 * Validates:
 * - The service container resolves JobExecutor to JobRunner by default.
 * - JobRunner implements JobExecutor.
 * - A custom JobExecutor bound in the container is used by the webhook controller.
 *
 * Does not validate:
 * - JobRunner execution logic (covered by WebhookAllowlistTest and WebhookExecutesJobTest).
 */
class JobExecutorBindingTest extends TestCase
{
    public function test_job_executor_resolves_to_job_runner_by_default(): void
    {
        $executor = app()->make(JobExecutor::class);

        $this->assertInstanceOf(JobRunner::class, $executor);
    }

    public function test_job_runner_implements_job_executor_contract(): void
    {
        $this->assertInstanceOf(JobExecutor::class, new JobRunner());
    }

    public function test_custom_job_executor_is_used_by_controller(): void
    {
        // Given: a custom executor that records the message it receives
        $received = null;
        $customExecutor = new class($received) implements JobExecutor {
            public function __construct(public mixed &$received) {}
            public function run(IncomingJobMessage $message): void
            {
                $this->received = $message->jobClass;
            }
        };

        $this->app->bind(JobExecutor::class, fn () => $customExecutor);

        config()->set('stateless-queue.allow_local_unverified', true);
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\Support\\Jobs\\*']);

        $job = new GoogleE2ETestJob('executor-swap');
        $messageData = [
            'job_class' => GoogleE2ETestJob::class,
            'payload'   => $job->getPayload(),
            'topic'     => 'test',
            'uuid'      => 'exec-1',
            'timestamp' => time(),
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush($messageData));

        // Then: the custom executor was called with the correct job class
        $response->assertStatus(200);
        $this->assertSame(GoogleE2ETestJob::class, $received);
    }
}

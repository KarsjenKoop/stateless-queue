<?php

namespace Karsjen\StatelessQueue\Contracts;

use Karsjen\StatelessQueue\Messages\IncomingJobMessage;

/**
 * Contract: JobExecutor
 *
 * Defines the execution boundary for incoming stateless jobs.
 *
 * The default implementation ({@see \Karsjen\StatelessQueue\Runtime\JobRunner}) validates the
 * job class against the allowed_jobs allowlist and runs it synchronously in the current process.
 *
 * Bind your own implementation in the service container to customise execution — for example
 * to add retry logic, dispatch to Laravel's standard queue, or attach observability hooks:
 *
 * ```php
 * $this->app->bind(JobExecutor::class, MyCustomJobExecutor::class);
 * ```
 *
 * @see \Karsjen\StatelessQueue\Runtime\JobRunner
 * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
 */
interface JobExecutor
{
    /**
     * Execute the job described by the incoming message.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\JobNotAllowedException if the job class is not in the allowlist.
     * @throws \RuntimeException on class resolution or execution failure.
     */
    public function run(IncomingJobMessage $message): void;
}

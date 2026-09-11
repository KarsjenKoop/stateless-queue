<?php

namespace Karsjen\StatelessQueue\Runtime;

use Illuminate\Support\Str;
use Karsjen\StatelessQueue\Contracts\JobExecutor;
use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Exceptions\JobNotAllowedException;
use Karsjen\StatelessQueue\Messages\IncomingJobMessage;
use RuntimeException;

/**
 * Runner: JobRunner
 *
 * Validates and executes a job reconstructed from an incoming webhook payload.
 *
 * ### How It Works
 * Given an IncomingJobMessage, the runner:
 * 1. Checks the job class against the `stateless-queue.allowed_jobs` allowlist (supports Str::is patterns).
 * 2. Confirms the class exists and implements ShouldStatelessQueue.
 * 3. Instantiates the job via the service container, passing the payload array as named constructor arguments.
 * 4. Calls handle() to execute the job.
 *
 * Input:  {@see \Karsjen\StatelessQueue\Messages\IncomingJobMessage} — parsed, verified payload from the adapter.
 * Output: void — side effects only. A disallowed class raises JobNotAllowedException; any other
 *         validation or resolution failure raises RuntimeException.
 *
 * This is the default {@see \Karsjen\StatelessQueue\Contracts\JobExecutor}. Bind your own
 * implementation of that contract to change how incoming jobs are executed.
 *
 * @see \Karsjen\StatelessQueue\Contracts\JobExecutor
 * @see \Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue
 * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
 */
final class JobRunner implements JobExecutor
{
    /**
     * Validates and executes the job carried by the message.
     *
     * The payload array is passed to the container as named constructor arguments, so any constructor
     * parameter absent from the payload is resolved by the container as a normal dependency.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\JobNotAllowedException
     *         if the class does not match any `stateless-queue.allowed_jobs` pattern.
     * @throws \RuntimeException if the class does not exist or does not implement ShouldStatelessQueue.
     * @throws \Throwable whatever the job's own handle() throws.
     */
    public function run(IncomingJobMessage $message): void
    {
        $jobClass = $message->jobClass;
        $payload = $message->payload;

        if (! $this->isJobClassAllowed($jobClass)) {
            throw JobNotAllowedException::notInAllowlist($jobClass);
        }

        if (! class_exists($jobClass)) {
            throw new RuntimeException("Job class [{$jobClass}] does not exist.");
        }

        if (! is_subclass_of($jobClass, ShouldStatelessQueue::class)) {
            throw new RuntimeException("Job class [{$jobClass}] must implement ShouldStatelessQueue.");
        }

        $job = app()->make($jobClass, $payload);
        $job->handle();
    }

    /**
     * Matches the class against `stateless-queue.allowed_jobs` using Str::is() patterns.
     *
     * Deny-by-default: an absent, non-array, or empty allowlist rejects every job.
     */
    private function isJobClassAllowed(string $jobClass): bool
    {
        $allowed = config('stateless-queue.allowed_jobs', []);

        if (! is_array($allowed) || $allowed === []) {
            return false;
        }

        foreach ($allowed as $pattern) {
            if (Str::is($pattern, $jobClass)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Karsjen\StatelessQueue\Exceptions;

use RuntimeException;

/**
 * Exception: JobNotAllowedException
 *
 * Thrown by JobRunner when an incoming webhook payload references a job class that is not
 * present in the `stateless-queue.allowed_jobs` allowlist.
 *
 * This is a security boundary — catching this exception separately allows the application
 * to alert on or log unauthorised job execution attempts distinct from other runtime failures.
 *
 * @see \Karsjen\StatelessQueue\Runtime\JobRunner
 */
final class JobNotAllowedException extends RuntimeException
{
    /** Raised when the job class does not match any pattern in the allowed_jobs allowlist. */
    public static function notInAllowlist(string $jobClass): self
    {
        return new self("Job class [{$jobClass}] is not in the stateless-queue allowed_jobs list.");
    }
}

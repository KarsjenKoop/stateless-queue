<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs\E2E;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * RealPushE2ESnakeTopicJob
 *
 * E2E test job for "real push" SNS/queue setup (snake_case topic).
 *
 * This job is used in end-to-end tests to verify stateless queue "real push"
 * adapter integration, using a specific SNS topic ('real-push-e2e-snake').
 * The presence of the real_push_e2e_marker.txt file in storage/app marks
 * successful job execution.
 *
 * Usage:
 *   Dispatch with queue system configured for stateless SNS topic delivery
 *   to the 'real-push-e2e-snake' topic. On execution, the job will write
 *   its label to the marker file.
 *
 * Fields:
 *   @property string $label           Unique label included in the marker file for assertion.
 *   @property string $stateless_topic The SNS topic used by this job (snake_case).
 *
 * Used by:
 *   - E2E/RealPush tests for SNS/StatelessQueue end-to-end delivery, snake_case SNS topic.
 */

class RealPushE2ESnakeTopicJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $label;

    public string $stateless_topic = 'real-push-e2e-snake';

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function handle(): void
    {
        $path = storage_path('app/real_push_e2e_marker.txt');
        @file_put_contents($path, $this->label);
    }
}

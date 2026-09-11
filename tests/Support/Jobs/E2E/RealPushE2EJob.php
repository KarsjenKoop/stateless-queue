<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs\E2E;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * RealPushE2EJob
 *
 * E2E test job for "real push" SNS/queue setup (non-default topic).
 *
 * This job is used in end-to-end tests to verify stateless queue "real push"
 * adapter integration, using a specific (non-default) SNS topic. The presence
 * of the real_push_e2e_marker.txt file in storage/app marks successful job execution.
 *
 * Usage:
 *   Dispatch with queue system configured for stateless SNS topic delivery to a
 *   specific topic ('real-push-e2e-topic'). On execution, the job will write its
 *   label to the marker file.
 *
 * Fields:
 *   @property string $label   Unique label included in the marker file for assertion.
 *   @property string $statelessTopic  The SNS topic used by this job (non-default).
 *
 * Used by:
 *   - E2E/RealPush tests for SNS/StatelessQueue end-to-end delivery, non-default SNS topic.
 */

class RealPushE2EJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $label;

    public string $statelessTopic = 'real-push-e2e-topic';

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

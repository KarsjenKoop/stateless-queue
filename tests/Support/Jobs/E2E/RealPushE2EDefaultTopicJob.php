<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs\E2E;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * RealPushE2EDefaultTopicJob
 *
 * E2E test job for default topic SNS/queue setup.
 *
 * This job is used in end-to-end tests to verify stateless queue "real push"
 * adapter integration, using the default SNS topic. The presence of the
 * real_push_e2e_marker.txt file in storage/app marks successful job execution.
 *
 * Usage:
 *   Dispatch with queue system configured for stateless SNS topic delivery.
 *   On execution, the job will write its label to the marker file.
 *
 * Fields:
 *   @property string $label  Unique label included in the marker file for assertion.
 *
 * Used by:
 *   - E2E/RealPush tests for SNS/StatelessQueue end-to-end delivery.
 */

class RealPushE2EDefaultTopicJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $label;

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

<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Default-topic job carrying mixed built-in types.
 *
 * Exercises the payload inference over an int and an array alongside a string — the three shapes
 * most jobs actually use.
 */
class GenerateReportJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(
        public string $reportId,
        public int $month,
        public array $columns,
    ) {}

    public function handle(): void
    {
        Log::info('stateless-queue-example', [
            'marker' => 'JOB_EXECUTED',
            'job' => 'GenerateReportJob',
            'topic' => 'default',
            'report_id' => $this->reportId,
            'month' => $this->month,
            'columns' => $this->columns,
        ]);
    }
}

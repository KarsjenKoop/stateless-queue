<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Default-topic job with a defaulted parameter and a service resolved at execution time.
 *
 * The service is deliberately *not* a constructor argument. A promoted class-typed property would be
 * rejected by payload inference, so dependencies are pulled from the container inside handle() —
 * the pattern the README recommends.
 */
class SyncInventoryJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $warehouse,
        public bool $full = false,
    ) {}

    public function handle(): void
    {
        // Resolved here rather than injected, so it never has to cross the wire.
        $config = app('config');

        Log::info('stateless-queue-example', [
            'marker' => 'JOB_EXECUTED',
            'job' => 'SyncInventoryJob',
            'topic' => 'default',
            'warehouse' => $this->warehouse,
            'full' => $this->full,
            'resolved_app_name' => $config->get('app.name'),
        ]);
    }
}

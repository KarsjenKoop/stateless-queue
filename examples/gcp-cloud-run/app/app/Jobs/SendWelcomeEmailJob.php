<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Default-topic job carrying two scalar arguments.
 *
 * Declares no topic, so it resolves to `stateless-queue.default_topic` — the third and last step of
 * topic resolution.
 */
class SendWelcomeEmailJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $email,
        public string $name = 'Customer',
    ) {}

    public function handle(): void
    {
        // Nothing is actually emailed. The log line is the observable effect the
        // verify step greps Cloud Logging for.
        Log::info('stateless-queue-example', [
            'marker' => 'JOB_EXECUTED',
            'job' => 'SendWelcomeEmailJob',
            'topic' => 'default',
            'email' => $this->email,
            'name' => $this->name,
        ]);
    }
}

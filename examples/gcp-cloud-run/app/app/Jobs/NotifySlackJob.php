<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

/**
 * Custom-topic job.
 *
 * `$statelessTopic` is the first step of topic resolution, so this publishes to its own topic and
 * arrives through its own push subscription. Both subscriptions point at the same webhook — routing
 * is a property of the topic, not of the endpoint.
 *
 * The topic name must match `var.custom_topic_name` in the Terraform.
 */
class NotifySlackJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $statelessTopic = 'stateless-notifications';

    public function __construct(
        public string $channel,
        public string $text,
    ) {}

    public function handle(): void
    {
        Log::info('stateless-queue-example', [
            'marker' => 'JOB_EXECUTED',
            'job' => 'NotifySlackJob',
            'topic' => $this->statelessTopic,
            'channel' => $this->channel,
            'text' => $this->text,
        ]);
    }
}

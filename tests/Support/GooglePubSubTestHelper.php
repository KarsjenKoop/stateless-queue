<?php

namespace Karsjen\StatelessQueue\Tests\Support;

use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Subscription;
use Google\Cloud\PubSub\Topic;
use Google\Auth\Credentials\InsecureCredentials;

/**
 * Thin wrapper around the Pub/Sub client for talking to the local emulator.
 *
 * Connects with InsecureCredentials, since the emulator performs no authentication, and provides the
 * topic/subscription plumbing plus the readiness probe that lets E2E tests skip cleanly rather than
 * fail when no emulator is running.
 *
 * Reads `GOOGLE_CLOUD_PROJECT` and `PUBSUB_EMULATOR_HOST` from the environment.
 */
class GooglePubSubTestHelper
{
    private PubSubClient $client;

    public function __construct(?string $projectId = null)
    {
        $projectId = $projectId ?: getenv('GOOGLE_CLOUD_PROJECT') ?: 'test-project';

        $this->client = new PubSubClient([
            'projectId' => $projectId,
            // When talking to the emulator we bypass real auth
            'credentials' => new InsecureCredentials(),
        ]);
    }

    /**
     * Quick connectivity check to determine whether the emulator
     * is reachable on PUBSUB_EMULATOR_HOST.
     */
    public static function isEmulatorAvailable(): bool
    {
        $hostPort = getenv('PUBSUB_EMULATOR_HOST');

        if (!$hostPort || !str_contains($hostPort, ':')) {
            return false;
        }

        [$host, $port] = explode(':', $hostPort, 2);
        $port = (int) $port;

        if ($port <= 0) {
            return false;
        }

        $connection = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if (!$connection) {
            return false;
        }

        fclose($connection);

        return true;
    }

    public function ensureTopic(string $topicName): Topic
    {
        $topic = $this->client->topic($topicName);

        if (!$topic->exists()) {
            $topic->create();
        }

        return $topic;
    }

    /**
     * Ensure a pull subscription exists for the given topic.
     */
    public function ensureSubscription(string $subscriptionName, string $topicName): Subscription
    {
        $topic = $this->ensureTopic($topicName);

        $subscription = $this->client->subscription($subscriptionName, $topic->name());

        if (!$subscription->exists()) {
            $subscription->create();
        }

        return $subscription;
    }

    /**
     * Ensure a push subscription exists for the given topic and endpoint.
     */
    public function ensurePushSubscription(string $subscriptionName, string $topicName, string $pushEndpoint): Subscription
    {
        $topic = $this->ensureTopic($topicName);

        $subscription = $this->client->subscription($subscriptionName, $topic->name());

        if (!$subscription->exists()) {
            $subscription->create([
                'pushConfig' => [
                    'pushEndpoint' => $pushEndpoint,
                ],
            ]);
        }

        return $subscription;
    }

    /**
     * Pull a single message from a subscription, polling for a short period.
     *
     * @return Message|null
     */
    public function pullSingleMessage(string $subscriptionName, int $attempts = 10, int $sleepSeconds = 1)
    {
        $subscription = $this->client->subscription($subscriptionName);

        for ($i = 0; $i < $attempts; $i++) {
            $messages = $subscription->pull([
                'maxMessages' => 1,
            ]);

            if (!empty($messages)) {
                /** @var Message $message */
                $message = $messages[0];

                // Acknowledge receipt so the emulator does not redeliver
                $subscription->acknowledge($message);

                return $message;
            }

            sleep($sleepSeconds);
        }

        return null;
    }
}


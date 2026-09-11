<?php

namespace Karsjen\StatelessQueue\Tests\E2E\Google;

use Karsjen\StatelessQueue\Tests\E2E\GooglePubSubE2ETestCase;
use Karsjen\StatelessQueue\Tests\Support\Jobs\GoogleE2ETestJob;
use PHPUnit\Framework\Attributes\Group;

#[Group('e2e')]
/**
 * E2E: Pub/Sub emulator -> HTTP webhook -> job handle().
 *
 * Context:
 * - Publishes into the emulator, pulls a message, then synthesizes an HTTP push payload and posts it to the webhook.
 * - This is the closest “real-world” exercise of the Google happy path without using real Google infrastructure.
 *
 * Validates:
 * - A message published into the emulator can be pulled, sent to the webhook, and executed successfully.
 * - The job writes a marker file proving it ran in the HTTP request boundary.
 *
 * Does not validate:
 * - Authentication/signature verification (this test relies on local bypass in the E2E base case).
 */
class GoogleHttpRoundTripE2ETest extends GooglePubSubE2ETestCase
{
    /**
     * Publishes a job to the emulator, then routes it through the webhook HTTP handler.
     *
     * Validates:
     * - The published message can be pulled and decoded as JSON.
     * - The webhook accepts a Google push-style payload built from that message and returns 200.
     * - The job’s observable side effects occur (static flags + marker file).
     *
     * Out of scope:
     * - Signature verification/authentication (local bypass is enabled in the E2E base case).
     * - Broker retry semantics (this test posts once).
     */
    public function test_google_job_flows_through_http_webhook(): void
    {
        // Given
        $this->requirePubSubEmulator();

        $helper = $this->pubSubHelper();

        $topic = 'stateless-queue-e2e-topic-http';
        $subscriptionName = 'stateless-queue-e2e-http-sub';

        $helper->ensureSubscription($subscriptionName, $topic);

        GoogleE2ETestJob::$handled = false;
        GoogleE2ETestJob::$handledMessage = null;

        // When
        $job = new GoogleE2ETestJob('Hello HTTP E2E');
        $job->statelessTopic = $topic;
        $job->push();

        $message = $helper->pullSingleMessage($subscriptionName, 10, 1);

        $this->assertNotNull($message, 'Expected a message to be available from the Pub/Sub emulator.');

        $data = json_decode($message->data(), true);

        $this->assertIsArray($data);

        // And: build a Google-style push payload using the message we pulled from the emulator
        $payload = [
            'message' => [
                'attributes' => array_merge(
                    $message->attributes(),
                    ['topic' => $data['topic'] ?? $topic]
                ),
                'data' => base64_encode(json_encode($data)),
            ],
        ];

        $response = $this->postJson(route('stateless.webhook'), $payload);

        // Then
        $response->assertStatus(200);

        $this->assertTrue(GoogleE2ETestJob::$handled);
        $this->assertSame('Hello HTTP E2E', GoogleE2ETestJob::$handledMessage);

        // And: verify that the marker file was written by the job
        $path = function_exists('base_path')
            ? base_path('stateless_http_e2e.txt')
            : getcwd() . DIRECTORY_SEPARATOR . 'stateless_http_e2e.txt';

        $this->assertFileExists($path);
        $this->assertSame('Hello HTTP E2E', trim(file_get_contents($path)));
    }
}


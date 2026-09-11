<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Webhook payload source detection and decode/parsing robustness.
 *
 * Validates:
 * - Unknown payload sources are rejected deterministically (400).
 * - Malformed Google push payloads are handled without throwing (200 handled).
 * - Malformed AWS SNS notification message bodies are rejected deterministically (422).
 *
 * Does not validate:
 * - Authentication/gating decisions (covered by `WebhookAuthenticationTest`).
 * - Allowlist enforcement (covered by `WebhookAllowlistTest`).
 * - Decoded job validation/instantiation/execution (covered by dispatch/execution tests).
 */
class WebhookPayloadParsingTest extends TestCase
{
    /**
     * Rejects bodies that match neither a Google push envelope nor an AWS SNS envelope.
     *
     * Validates:
     * - The controller returns 400 with "Unknown payload source" when the payload cannot be attributed
     *   to any supported provider.
     *
     * Out of scope:
     * - Authentication/gating (middleware bypassed).
     */
    public function test_unknown_payload_source_returns_400(): void
    {
        // Given
        $payload = ['foo' => 'bar'];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), $payload);

        // Then
        $response->assertStatus(400)
            ->assertJson(['error' => 'Unknown payload source']);
    }

    /**
     * Handles malformed Google push `message.data` safely and acknowledges the request.
     *
     * Validates:
     * - Invalid base64/non-JSON in `message.data` does not throw.
     * - The webhook responds `200 {"status":"handled"}` (acknowledged, no job dispatched).
     *
     * Why this matters:
     * - Push providers retry on non-2xx. Returning 2xx for un-decodable messages prevents retry storms.
     */
    public function test_invalid_message_data_is_handled_without_error_for_google_push(): void
    {
        // Given: invalid base64/non-JSON in message.data
        $payload = [
            'message' => [
                'data' => 'not-valid-base64-json!!!',
                'attributes' => ['topic' => 'google-http-parsing-topic'],
            ],
        ];

        // When
        $response = $this->withoutMiddleware()
            ->postJson(route('stateless.webhook'), $payload);

        // Then: parser returns null, request is treated as handled
        $response->assertStatus(200)->assertJson(['status' => 'handled']);
    }

    /**
     * Treats invalid AWS SNS `Message` JSON as an error condition.
     *
     * Validates:
     * - When the request is recognized as SNS Notification but `Message` is not valid JSON,
     *   the webhook returns 422.
     *
     * Out of scope:
     * - SNS signature verification (middleware bypassed).
     */
    public function test_invalid_message_json_returns_422_for_aws_sns_notification(): void
    {
        // Given: Message is not valid JSON
        $payload = [
            'Type' => 'Notification',
            'Message' => '{invalid-json',
            'MessageAttributes' => [
                'topic' => [
                    'Type' => 'String',
                    'Value' => 'aws-http-parsing-topic',
                ],
            ],
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:aws-http-parsing-topic',
        ];

        // When
        $response = $this->withoutMiddleware()
            ->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), $payload);

        // Then
        $response->assertUnprocessable();
    }

    /**
     * Pins the minimal contract of `WebhookPayloadBuilder::googlePush()` used throughout feature tests.
     *
     * Validates:
     * - The helper produces `message.attributes.topic` and `message.data` with base64 JSON string.
     */
    public function test_payload_builder_produces_expected_shape_for_google_push(): void
    {
        // Given
        $messageData = [
            'uuid' => 'shape-1',
            'job_class' => 'App\\Jobs\\Whatever',
            'topic' => 'shape-topic',
            'payload' => ['x' => 'y'],
            'timestamp' => time(),
        ];

        // When
        $payload = WebhookPayloadBuilder::googlePush($messageData);

        // Then
        $this->assertIsArray($payload['message']['attributes'] ?? null);
        $this->assertSame('shape-topic', $payload['message']['attributes']['topic'] ?? null);
        $this->assertIsString($payload['message']['data'] ?? null);
    }

    /**
     * Pins the minimal contract of `WebhookPayloadBuilder::awsSnsNotification()` used throughout feature tests.
     *
     * Validates:
     * - The helper produces `Type=Notification`, a JSON string `Message`, and a topic attribute.
     */
    public function test_payload_builder_produces_expected_shape_for_aws_sns_notification(): void
    {
        // Given
        $messageData = [
            'uuid' => 'shape-aws-1',
            'job_class' => 'App\\Jobs\\Whatever',
            'topic' => 'shape-topic-aws',
            'payload' => ['x' => 'y'],
            'timestamp' => time(),
        ];

        // When
        $payload = WebhookPayloadBuilder::awsSnsNotification($messageData);

        // Then
        $this->assertSame('Notification', $payload['Type'] ?? null);
        $this->assertIsString($payload['Message'] ?? null);
        $this->assertSame('shape-topic-aws', $payload['MessageAttributes']['topic']['Value'] ?? null);
    }
}


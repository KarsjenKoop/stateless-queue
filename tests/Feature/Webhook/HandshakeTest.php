<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * AWS SNS subscription handshake (SubscriptionConfirmation).
 *
 * Context:
 * - SNS sends a SubscriptionConfirmation message when a subscription is first created.
 * - The webhook must confirm the subscription by requesting `SubscribeURL`, otherwise SNS will not
 *   begin delivering notifications.
 *
 * Validates:
 * - When AWS sends a SubscriptionConfirmation, the webhook confirms by visiting SubscribeURL.
 *
 * Does not validate:
 * - AWS signature verification (middleware is bypassed).
 * - Job execution (this flow does not dispatch a job).
 */
class HandshakeTest extends TestCase
{
    /**
     * Confirms an AWS SNS subscription by calling the provided `SubscribeURL`.
     *
     * Validates:
     * - Posting a minimal SNS `SubscriptionConfirmation` payload triggers an outbound HTTP request to
     *   the exact `SubscribeURL` contained in the payload.
     * - The webhook returns 200, acknowledging the confirmation request.
     *
     * Payload context:
     * - Header `x-amz-sns-message-type: SubscriptionConfirmation` identifies the SNS handshake path.
     *
     * The URL is a genuine SNS host. The adapter pins the SubscribeURL host, so a confirmation
     * pointing anywhere else is refused without a request — see `AwsSnsSubscribeUrlTest`.
     *
     * Out of scope:
     * - SNS signature verification (middleware bypassed).
     * - Error handling if `SubscribeURL` returns non-200 (not exercised here).
     * - Which hosts are trusted (covered by `AwsSnsSubscribeUrlTest`).
     */
    public function test_it_confirms_aws_subscription_automatically(): void
    {
        // Given
        Http::fake([
            'sns.us-east-1.amazonaws.com/*' => Http::response('OK', 200),
        ]);

        $payload = [
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=123',
            'Token' => '123',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:test',
            'Message' => 'test'
        ];

        // When
        $response = $this->withoutMiddleware()
                         ->withHeaders(['x-amz-sns-message-type' => 'SubscriptionConfirmation'])
                         ->postJson(route('stateless.webhook'), $payload);

        // Then
        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=123';
        });
    }
}
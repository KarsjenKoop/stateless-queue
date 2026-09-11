<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Regression guard: credentials must never reach the log.
 *
 * Captures every MessageLogged event raised during a request and asserts the rendered message and
 * context are free of the credential material the request carried.
 *
 * Validates:
 * - A failed Google token verification does not log the raw bearer token.
 * - A failed request does not log the `?secret=` webhook secret or the full request URL.
 * - Adapter and middleware log context carries identifiers only, never signature material.
 *
 * Does not validate:
 * - Whether verification itself reaches the right verdict (covered by `WebhookAuthenticationTest`).
 *
 * Extend this class whenever you add a log call anywhere in `src/`.
 */
class WebhookSecurityLoggingTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $loggedBodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggedBodies = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->loggedBodies[] = $e->message.json_encode($e->context);
        });
    }

    public function test_google_auth_failure_logs_do_not_contain_raw_bearer_token(): void
    {
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);

        $token = 'super-secret-google-test-token-'.bin2hex(random_bytes(16));

        $response = $this->withHeaders([
                'X-Goog-Pubsub-Subscription-Name' => 'projects/my-project/subscriptions/my-sub',
                'Authorization' => 'Bearer '.$token,
            ])
            ->postJson(route('stateless.webhook'), ['foo' => 'bar']);

        $response->assertStatus(403);

        foreach ($this->loggedBodies as $body) {
            $this->assertStringNotContainsString($token, $body);
            $this->assertStringNotContainsString('Bearer '.$token, $body);
        }
    }

    public function test_failed_secret_bypass_does_not_log_query_secret_values(): void
    {
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', 'server-side-webhook-secret');

        $guess = 'client-wrong-secret-guess';

        $response = $this->postJson(route('stateless.webhook', ['secret' => $guess]), ['foo' => 'bar']);

        $response->assertStatus(401);

        foreach ($this->loggedBodies as $body) {
            $this->assertStringNotContainsString($guess, $body);
            $this->assertStringNotContainsString('server-side-webhook-secret', $body);
        }
    }

    /**
     * SNS verification must not write Signature, cert URL secrets, or unsubscribe tokens into logs or JSON responses.
     */
    public function test_aws_sns_verification_path_does_not_leak_signature_material(): void
    {
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);

        $uniqueSig = 'UNIQUE_SNS_SIGNATURE_'.bin2hex(random_bytes(16));
        $uniqueCertPath = 'UNIQUE_SIGNING_CERT_PATH_'.bin2hex(random_bytes(8)).'.pem';
        $uniqueUnsubToken = 'UNIQUE_UNSUB_TOKEN_'.bin2hex(random_bytes(12));

        $payload = [
            'Type' => 'Notification',
            'MessageId' => 'test-msg-id-security-logging',
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
            'Subject' => null,
            'Message' => json_encode(['probe' => true]),
            'Timestamp' => gmdate('c'),
            'SignatureVersion' => '1',
            'Signature' => $uniqueSig,
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/'.$uniqueCertPath,
            'UnsubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=Unsubscribe&Token='.urlencode($uniqueUnsubToken),
        ];

        $response = $this->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), $payload);

        $response->assertStatus(403);
        $responseBody = $response->getContent();
        $this->assertStringNotContainsString($uniqueSig, $responseBody);
        $this->assertStringNotContainsString($uniqueCertPath, $responseBody);
        $this->assertStringNotContainsString($uniqueUnsubToken, $responseBody);

        foreach ($this->loggedBodies as $body) {
            $this->assertStringNotContainsString($uniqueSig, $body);
            $this->assertStringNotContainsString($uniqueCertPath, $body);
            $this->assertStringNotContainsString($uniqueUnsubToken, $body);
        }
    }

    public function test_aws_sns_subscription_confirmation_does_not_leak_token_on_failure(): void
    {
        // AWS verifier is skipped in local/testing bypass so we can reach the adapter's parseRequest.
        config()->set('stateless-queue.allow_local_unverified', true);
        
        $token = 'SENSITIVE_SNS_SUB_TOKEN_'.bin2hex(random_bytes(16));
        $subscribeUrl = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn:aws:sns:us-east-1:123456789012:test&Token='.$token;

        // Force the Http::get to fail with an exception that might contain the URL.
        \Illuminate\Support\Facades\Http::fake(function() {
            throw new \RuntimeException('Connection failed to AWS');
        });

        $payload = [
            'Type' => 'SubscriptionConfirmation',
            'Token' => $token,
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:test',
            'SubscribeURL' => $subscribeUrl,
            'Timestamp' => gmdate('c'),
            'SignatureVersion' => '1',
            'Signature' => 'dummy',
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Message' => 'dummy',
            'MessageId' => 'dummy',
        ];

        $response = $this->withHeaders(['x-amz-sns-message-type' => 'SubscriptionConfirmation'])
            ->postJson(route('stateless.webhook'), $payload);

        // A failed SubscribeURL fetch is our HTTP call failing, not a malformed payload from AWS, so
        // the adapter raises a plain RuntimeException and the controller classifies it as internal (500).
        // The status is incidental here — what this test guards is that no token reaches the response
        // body or the log, whichever branch handles it.
        $response->assertStatus(500);

        // The URL/token should NOT be in the JSON response or the logs.
        $this->assertStringNotContainsString($token, $response->getContent());

        foreach ($this->loggedBodies as $body) {
            $this->assertStringNotContainsString($token, $body);
        }
    }
}

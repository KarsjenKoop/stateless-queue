<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Karsjen\StatelessQueue\Adapters\AwsSnsAdapter;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Host pinning on the SNS SubscribeURL that `AwsSnsAdapter` fetches to confirm a subscription.
 *
 * Confirming a subscription means issuing an outbound GET to a URL taken from the request body. In
 * production, signature verification proves that body came from SNS — but `allow_local_unverified`
 * turns that check off, and with it off an unauthenticated caller can hand the application any URL
 * and use it as a proxy to reach addresses it cannot reach directly: cloud metadata endpoints,
 * private-range services, admin ports bound to loopback.
 *
 * Validates:
 * - Against real AWS (no custom endpoint), only HTTPS on an SNS-owned host is fetched.
 * - The classic SSRF targets — link-local metadata, loopback, private ranges — are refused.
 * - A lookalike host that merely *starts* with the SNS pattern is refused, since the pattern is
 *   anchored at both ends.
 * - Non-HTTP schemes are refused.
 * - A refusal is silent to the caller but recorded: no request is sent, and the handshake still
 *   answers 200 rather than provoking redelivery.
 * - With a custom endpoint configured the rule relaxes, because emulators advertise their own
 *   hostnames — LocalStack answers `localhost.localstack.cloud` whatever address you dialled.
 *
 * Does not validate:
 * - Signature verification itself (covered by `WebhookAuthenticationTest`).
 * - That the confirmation actually succeeds at AWS; only which URLs we are willing to dial.
 */
class AwsSnsSubscribeUrlTest extends TestCase
{
    private function adapter(?string $endpoint = null): AwsSnsAdapter
    {
        return new AwsSnsAdapter(array_filter([
            'region' => 'us-east-1',
            'account_id' => '000000000000',
            'endpoint' => $endpoint,
        ]));
    }

    private function confirmationRequest(string $subscribeUrl): Request
    {
        $request = Request::create('/api/stateless/webhook', 'POST', [], [], [], [
            'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => 'SubscriptionConfirmation',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => 'arn:aws:sns:us-east-1:000000000000:topic',
            'Token' => 'token-value',
            'SubscribeURL' => $subscribeUrl,
        ]));

        $request->headers->set('x-amz-sns-message-type', 'SubscriptionConfirmation');

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private static function trustedUrls(): array
    {
        return [
            'us-east-1'       => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'eu-west-2'       => 'https://sns.eu-west-2.amazonaws.com/?Action=ConfirmSubscription',
            'govcloud'        => 'https://sns.us-gov-west-1.amazonaws.com/?Action=ConfirmSubscription',
            'china partition' => 'https://sns.cn-north-1.amazonaws.com.cn/?Action=ConfirmSubscription',
            'uppercase host'  => 'https://SNS.US-EAST-1.AMAZONAWS.COM/?Action=ConfirmSubscription',
        ];
    }

    public function test_genuine_sns_hosts_are_confirmed(): void
    {
        foreach (self::trustedUrls() as $label => $url) {
            // Given
            Http::fake();

            // When
            $result = $this->adapter()->parseRequest($this->confirmationRequest($url));

            // Then
            $this->assertSame('handshake', $result->kind, "case: {$label}");
            Http::assertSentCount(1);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function untrustedUrls(): array
    {
        return [
            'ec2 / gcp metadata'      => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'metadata over https'     => 'https://169.254.169.254/latest/meta-data/',
            'loopback'                => 'http://127.0.0.1:6379/',
            'localhost by name'       => 'http://localhost:9200/_cluster/health',
            'private range'           => 'http://10.0.0.7:5432/',
            'arbitrary external host' => 'https://attacker.test/collect',
            'suffix lookalike'        => 'https://sns.us-east-1.amazonaws.com.attacker.test/',
            'prefix lookalike'        => 'https://notsns.us-east-1.amazonaws.com/',
            'subdomain lookalike'     => 'https://sns.us-east-1.amazonaws.com.evil.co.uk/',
            'plain http on sns host'  => 'http://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'file scheme'             => 'file:///etc/passwd',
            'gopher scheme'           => 'gopher://127.0.0.1:6379/_INFO',
            'no host'                 => '/latest/meta-data/',
        ];
    }

    public function test_untrusted_subscribe_urls_are_never_fetched(): void
    {
        foreach (self::untrustedUrls() as $label => $url) {
            // Given
            Http::fake();

            // When
            $result = $this->adapter()->parseRequest($this->confirmationRequest($url));

            // Then — the message is still handled (200), we simply refuse to dial the URL.
            $this->assertSame('handshake', $result->kind, "case: {$label}");
            $this->assertSame(200, $result->httpStatus, "case: {$label}");
            Http::assertNothingSent();
        }
    }

    public function test_refusal_is_recorded_in_the_log(): void
    {
        // Given
        Http::fake();
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function ($e) use (&$logged): void {
                $logged[] = $e->message;
            },
        );

        // When
        $this->adapter()->parseRequest($this->confirmationRequest('http://169.254.169.254/latest/meta-data/'));

        // Then
        $this->assertNotEmpty(
            array_filter($logged, fn ($m) => str_contains($m, 'refused SNS subscription confirmation')),
            'A refused SubscribeURL must leave a warning in the log.',
        );
    }

    public function test_missing_subscribe_url_sends_nothing(): void
    {
        // Given
        Http::fake();
        $request = Request::create('/api/stateless/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['Type' => 'SubscriptionConfirmation', 'Token' => 'x']));
        $request->headers->set('x-amz-sns-message-type', 'SubscriptionConfirmation');

        // When
        $result = $this->adapter()->parseRequest($request);

        // Then
        $this->assertSame('handshake', $result->kind);
        Http::assertNothingSent();
    }

    public function test_custom_endpoint_relaxes_the_rule_for_emulator_hostnames(): void
    {
        // Given — the host LocalStack actually advertises, which matches neither the AWS pattern nor
        // the endpoint the client was configured with. Pinning to either would break local E2E.
        Http::fake();
        $localstackUrl = 'http://localhost.localstack.cloud:4566/?Action=ConfirmSubscription&Token=abc';

        // When
        $result = $this->adapter('http://127.0.0.1:4566')->parseRequest($this->confirmationRequest($localstackUrl));

        // Then
        $this->assertSame('handshake', $result->kind);
        Http::assertSentCount(1);
    }

    public function test_custom_endpoint_still_rejects_non_http_schemes(): void
    {
        // Given — relaxed is not unconditional.
        Http::fake();

        // When
        $result = $this->adapter('http://127.0.0.1:4566')->parseRequest($this->confirmationRequest('file:///etc/passwd'));

        // Then
        $this->assertSame('handshake', $result->kind);
        Http::assertNothingSent();
    }
}

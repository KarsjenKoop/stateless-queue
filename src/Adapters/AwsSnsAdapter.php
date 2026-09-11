<?php

namespace Karsjen\StatelessQueue\Adapters;

use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Karsjen\StatelessQueue\Contracts\QueueProviderAdapter;
use Karsjen\StatelessQueue\Exceptions\AdapterPublishException;
use Karsjen\StatelessQueue\Messages\IncomingJobMessage;
use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Parsing\ParseResult;
use Aws\Sns\MessageValidator;
use Aws\Sns\Message;
use RuntimeException;

/**
 * Adapter: AwsSnsAdapter
 *
 * Handles outbound publishing to AWS SNS topics and inbound webhook delivery from SNS push subscriptions.
 *
 * ### Outbound
 * Publishes an OutgoingJobMessage to the configured SNS topic. Accepts a full topic ARN or a bare topic
 * name — in the latter case the ARN is resolved from the configured region and account ID.
 *
 * ### Inbound
 * Recognises SNS push requests by the `x-amz-sns-message-type` header or the presence of `Type` + `Message`
 * fields in the JSON body. Handles SubscriptionConfirmation automatically and parses Notification messages
 * into an IncomingJobMessage.
 *
 * Signature verification delegates to the AWS SNS MessageValidator library.
 *
 * SubscriptionConfirmation is answered by fetching the SubscribeURL from the request body. That host
 * is pinned to SNS to keep the endpoint from being used as a proxy into internal addresses — see
 * {@see self::isTrustedSubscribeUrl()}.
 *
 * @see \Karsjen\StatelessQueue\Contracts\QueueProviderAdapter
 * @see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage
 * @see \Karsjen\StatelessQueue\Messages\IncomingJobMessage
 */
final class AwsSnsAdapter implements QueueProviderAdapter
{
    private ?SnsClient $sns = null;
    private array $clientConfig = [];
    private ?string $accountId;
    private string $region;
    private ?string $endpoint;

    /**
     * Builds the SNS client config from the connection settings.
     * Explicit key/secret credentials are optional — omitting them falls back to the AWS credential chain.
     */
    public function __construct(array $config)
    {
        $this->region = $config['region'] ?? 'us-east-1';
        $this->accountId = $config['account_id'] ?? null;

        $this->endpoint = ! empty($config['endpoint']) ? (string) $config['endpoint'] : null;

        $clientConfig = ['version' => 'latest', 'region' => $this->region];
        if ($this->endpoint !== null) {
            $clientConfig['endpoint'] = $this->endpoint;
        }
        if (!empty($config['key']) && !empty($config['secret'])) {
            $clientConfig['credentials'] = ['key' => $config['key'], 'secret' => $config['secret']];
        }
        $this->clientConfig = $clientConfig;
    }

    public function name(): string
    {
        return 'aws';
    }

    /**
     * Publishes a job message to the SNS topic, including job class, UUID, and topic as message attributes.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\AdapterPublishException on SNS client failure.
     * @throws \RuntimeException if the topic is a bare name and no account ID is configured.
     */
    public function publish(OutgoingJobMessage $message): void
    {
        $topicArn = $this->resolveTopicArn($message->topic);

        try {
            $this->sns()->publish([
                'TopicArn' => $topicArn,
                'Message' => $message->toJson(),
                'MessageAttributes' => [
                    'job_class' => ['DataType' => 'String', 'StringValue' => $message->jobClass],
                    'uuid' => ['DataType' => 'String', 'StringValue' => $message->uuid],
                    'topic' => ['DataType' => 'String', 'StringValue' => $message->topic],
                ],
            ]);
        } catch (AwsException $e) {
            throw AdapterPublishException::forAdapter('aws', $e->getMessage(), $e);
        }
    }

    /**
     * Returns true if the request carries the SNS message type header or a Type + Message JSON body.
     */
    public function supportsRequest(Request $request): bool
    {
        if ($request->hasHeader('x-amz-sns-message-type')) {
            return true;
        }

        $payload = $request->json()->all();

        return is_array($payload)
            && isset($payload['Type'])
            && isset($payload['Message']);
    }

    /**
     * Verifies the SNS message signature using the AWS MessageValidator library.
     * Exception messages are never logged — they may echo SNS payload fields (Signature, Token, etc.).
     */
    public function verifySignature(Request $request): bool
    {
        try {
            $message = new Message($request->json()->all());
            $validator = new MessageValidator();

            if ($validator->isValid($message)) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::error('StatelessQueue: AWS signature verification failed', [
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    /**
     * Parses the SNS push payload into a ParseResult.
     * SubscriptionConfirmation messages are confirmed automatically and returned as a handshake.
     * Notification messages are decoded into an IncomingJobMessage.
     * Any other message type is ignored.
     */
    public function parseRequest(Request $request): ParseResult
    {
        $messageType = $request->header('x-amz-sns-message-type');
        $payload = $request->json()->all();

        if ($messageType === 'SubscriptionConfirmation') {
            $this->confirmSubscription($payload);
            return ParseResult::handshake();
        }

        if ($messageType === 'Notification') {
            $raw = $payload['Message'] ?? '{}';
            $attributes = $payload['MessageAttributes'] ?? [];

            $incoming = IncomingJobMessage::fromJson($raw, $attributes, 'aws_sns');
            return ParseResult::job($incoming);
        }

        return ParseResult::ignore();
    }

    /**
     * Confirms an SNS subscription by fetching the SubscribeURL provided in the payload.
     *
     * A payload without a SubscribeURL is a no-op — SNS only sends one on SubscriptionConfirmation.
     *
     * @param  array<string, mixed>  $payload  The decoded SNS envelope.
     *
     * @throws \RuntimeException if the HTTP request to the SubscribeURL fails.
     */
    private function confirmSubscription(array $payload): void
    {
        $url = $payload['SubscribeURL'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        if (! $this->isTrustedSubscribeUrl($url)) {
            // Refusing is the correct outcome, not a failure: the message was handled, we simply did
            // not act on it. Returning normally keeps the handshake a 200 so the provider does not
            // redeliver, while the warning records the attempt.
            Log::warning('StatelessQueue: refused SNS subscription confirmation to an untrusted host', [
                'host' => parse_url($url, PHP_URL_HOST) ?: '(unparseable)',
                'scheme' => parse_url($url, PHP_URL_SCHEME) ?: '(none)',
            ]);

            return;
        }

        try {
            Http::get($url);
            Log::info('StatelessQueue: AWS SNS subscription confirmed');
        } catch (\Throwable $e) {
            throw new RuntimeException('StatelessQueue: AWS SNS subscription confirmation failed.', 0, $e);
        }
    }

    /**
     * Whether a SubscribeURL is safe to fetch.
     *
     * Confirming a subscription means issuing an outbound GET to a URL taken from the request body.
     * Signature verification normally proves the body came from SNS, but `allow_local_unverified`
     * turns that check off — and with it off, an unauthenticated caller could hand us any URL and use
     * the application as a proxy to reach internal addresses: cloud metadata endpoints
     * (169.254.169.254), private-range services, or admin ports bound to loopback.
     *
     * Against real AWS the host is therefore pinned: HTTPS on `sns.<region>.amazonaws.com`, or the
     * `.amazonaws.com.cn` equivalent for the China partitions. The pattern anchors both ends, so a
     * lookalike such as `sns.us-east-1.amazonaws.com.attacker.test` does not match.
     *
     * When a custom `endpoint` is configured the deployment is by definition not talking to AWS — it
     * is an emulator or a private SNS-compatible service — and those advertise their own hostnames
     * independently of the endpoint dialled (LocalStack answers `localhost.localstack.cloud` however
     * you reach it). The AWS host pattern cannot apply there, so it is relaxed to http/https with a
     * parseable host. That relaxation is reachable only by an operator who explicitly pointed the
     * adapter away from AWS; a production deployment leaves `endpoint` unset and gets the strict rule.
     */
    private function isTrustedSubscribeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if ($this->endpoint !== null) {
            return true;
        }

        return $scheme === 'https'
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host) === 1;
    }

    /**
     * Resolves a topic name to a full SNS ARN.
     * Passes full ARNs through unchanged; constructs the ARN from region and account ID for bare names.
     *
     * @throws \RuntimeException if a bare name is given but no account ID is configured.
     */
    private function resolveTopicArn(string $topic): string
    {
        if (str_starts_with($topic, 'arn:aws:sns:')) {
            return $topic;
        }
        if (!$this->accountId) {
            throw new RuntimeException('AWS Account ID is required in config to resolve topic name to ARN.');
        }
        return "arn:aws:sns:{$this->region}:{$this->accountId}:{$topic}";
    }

    /** Lazy-initialises and returns the SnsClient. */
    private function sns(): SnsClient
    {
        if ($this->sns === null) {
            $this->sns = new SnsClient($this->clientConfig);
        }

        return $this->sns;
    }
}

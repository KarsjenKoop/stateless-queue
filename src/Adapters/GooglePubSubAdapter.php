<?php

namespace Karsjen\StatelessQueue\Adapters;

use Google\Cloud\PubSub\PubSubClient;
use Karsjen\StatelessQueue\Contracts\QueueProviderAdapter;
use Karsjen\StatelessQueue\Exceptions\AdapterPublishException;
use Karsjen\StatelessQueue\Exceptions\WebhookParseException;
use Karsjen\StatelessQueue\Messages\IncomingJobMessage;
use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Parsing\ParseResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Google\Auth\AccessToken;
use RuntimeException;

/**
 * Adapter: GooglePubSubAdapter
 *
 * Handles outbound publishing to Google Cloud Pub/Sub topics and inbound webhook delivery from Pub/Sub push subscriptions.
 *
 * ### Outbound
 * Publishes an OutgoingJobMessage to the configured Pub/Sub topic. The message body is JSON;
 * job class and UUID are also sent as Pub/Sub message attributes.
 *
 * ### Inbound
 * Recognises Pub/Sub push requests by the `X-Goog-Pubsub-Subscription-Name` header or the presence of
 * a `message.data` field in the JSON body. The data field is base64-decoded and parsed into an IncomingJobMessage.
 *
 * Signature verification validates the Google-issued OIDC bearer token in the Authorization header,
 * then enforces the policy defined in `stateless-queue.connections.google.auth`
 * (audience, issuer, email allowlist, email suffix allowlist, email_verified).
 *
 * @see \Karsjen\StatelessQueue\Contracts\QueueProviderAdapter
 * @see \Karsjen\StatelessQueue\Messages\OutgoingJobMessage
 * @see \Karsjen\StatelessQueue\Messages\IncomingJobMessage
 */
final class GooglePubSubAdapter implements QueueProviderAdapter
{
    private array $config;
    private ?PubSubClient $pubSub = null;

    /**
     * @param  array<string, mixed>  $config  The `stateless-queue.connections.google` block:
     *                                        `project_id`, `key_file`, and the `auth` policy array.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'google';
    }

    /**
     * Publishes a job message to the configured Pub/Sub topic.
     * Job class, UUID, and topic are also sent as message attributes for routing and filtering.
     *
     * @throws AdapterPublishException on Pub/Sub client failure.
     */
    public function publish(OutgoingJobMessage $message): void
    {
        $topic = $this->pubSub()->topic($message->topic);

        try {
            $topic->publish([
                'data' => $message->toJson(),
                'attributes' => [
                    'job_class' => $message->jobClass,
                    'uuid' => $message->uuid,
                    'topic' => $message->topic
                ]
            ]);
        } catch (\Throwable $e) {
            throw AdapterPublishException::forAdapter('google', $e->getMessage(), $e);
        }
    }

    /**
     * Returns true if the request carries the Pub/Sub subscription header or a message.data body structure.
     */
    public function supportsRequest(Request $request): bool
    {
        if ($request->hasHeader('X-Goog-Pubsub-Subscription-Name')) {
            return true;
        }

        $payload = $request->json()->all();
        return is_array($payload)
            && isset($payload['message'])
            && is_array($payload['message'])
            && array_key_exists('data', $payload['message']);
    }

    /**
     * Decodes the base64 Pub/Sub message data and returns a ParseResult::job().
     *
     * A `data` field that is not valid base64 is treated as a provider lifecycle probe rather than a
     * job, and returns ParseResult::handshake() so the subscription is acknowledged with 200.
     *
     * @throws \Karsjen\StatelessQueue\Exceptions\WebhookParseException
     *         if `message` is absent or carries no `data` field, or if the decoded body is not a valid envelope.
     */
    public function parseRequest(Request $request): ParseResult
    {
        $message = $request->input('message');
        if (!is_array($message) || !isset($message['data'])) {
            Log::warning('StatelessQueue: Google adapter received invalid structure');
            throw WebhookParseException::invalidStructure();
        }
        $decoded = base64_decode((string) $message['data'], true);
        if ($decoded === false) {
            return ParseResult::handshake();
        }
        $attributes = $message['attributes'] ?? [];
        $incoming = IncomingJobMessage::fromJson($decoded, is_array($attributes) ? $attributes : [], 'google_pubsub');
        return ParseResult::job($incoming);
    }

    /**
     * Validates the Google OIDC bearer token and enforces the auth policy from config.
     *
     * Returns false — never throws — so the middleware can respond 403 uniformly regardless of
     * whether the token was absent, malformed, unverifiable, or simply not allowlisted.
     *
     * Nothing derived from the token reaches the log: failures are recorded as an exception class or
     * a fixed reason code, never as a library message that may quote the token back.
     */
    public function verifySignature(Request $request): bool
    {
        $token = $this->extractBearerToken($request->header('Authorization'));
        if ($token === null) {
            Log::warning('StatelessQueue: Google token missing bearer authorization');
            return false;
        }
        try {
            $claims = $this->verifyGoogleToken($token);
            if (! $this->validateClaimsAgainstPolicy($claims)) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            // Exception class only. A JWT library raising on a malformed or unverifiable token has the
            // token in hand, and libraries routinely quote the offending input back in the message —
            // a segment of it, the decoded header, or the whole string. Logging that message would put
            // a live credential in the log, which is exactly what an attacker who reaches the log is
            // looking for. The class name is enough to tell malformed from expired from unreachable.
            Log::error('StatelessQueue: Google token verification failed', [
                'exception' => $e::class,
            ]);
            return false;
        }
    }

    /**
     * Verifies the raw JWT with Google's public keys and returns the decoded claims.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException if verification fails or does not return an array of claims.
     */
    private function verifyGoogleToken(string $token): array
    {
        $claims = (new AccessToken())->verify($token);
        if (! is_array($claims)) {
            throw new RuntimeException('Google token verification did not return claims.');
        }
        return $claims;
    }

    /**
     * Extracts the bearer token from an Authorization header value.
     * Returns null if the header is absent, empty, or not a Bearer token.
     */
    private function extractBearerToken(?string $authorization): ?string
    {
        if (! is_string($authorization) || $authorization === '') {
            return null;
        }
        if (! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($authorization, 7));
        return $token !== '' ? $token : null;
    }

    /**
     * Whether the JWT `aud` claim includes the configured audience.
     * `aud` may be a string or an array of strings per the JWT spec.
     */
    private function audienceIncludesExpected(mixed $audClaim, string $expectedAudience): bool
    {
        if (is_string($audClaim)) {
            return $audClaim === $expectedAudience;
        }

        if (is_array($audClaim)) {
            foreach ($audClaim as $item) {
                if (is_string($item) && $item === $expectedAudience) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Validates decoded JWT claims against the auth policy defined in config.
     * Rejects on audience mismatch, issuer mismatch, unverified email, or email not in allowlist.
     *
     * @param array<string, mixed> $claims
     */
    private function validateClaimsAgainstPolicy(array $claims): bool
    {
        $policy = (array) ($this->config['auth'] ?? []);
        $expectedAudience = $policy['expected_audience'] ?? null;
        $allowedIssuers = is_array($policy['allowed_issuers'] ?? null) ? $policy['allowed_issuers'] : [];
        $allowedServiceAccounts = is_array($policy['allowed_service_accounts'] ?? null) ? $policy['allowed_service_accounts'] : [];
        $allowedEmailSuffixes = is_array($policy['allowed_email_suffixes'] ?? null) ? $policy['allowed_email_suffixes'] : [];
        $requireEmailVerified = (bool) ($policy['require_email_verified'] ?? true);
        $iss = isset($claims['iss']) ? (string) $claims['iss'] : null;
        $email = isset($claims['email']) ? (string) $claims['email'] : null;
        $emailVerified = $claims['email_verified'] ?? null;
        if (is_string($expectedAudience) && $expectedAudience !== '') {
            if (! array_key_exists('aud', $claims) || ! $this->audienceIncludesExpected($claims['aud'], $expectedAudience)) {
                Log::warning('StatelessQueue: Google auth rejected', ['reason' => 'aud_mismatch']);
                return false;
            }
        }
        if ($allowedIssuers !== [] && ($iss === null || ! in_array($iss, $allowedIssuers, true))) {
            Log::warning('StatelessQueue: Google auth rejected', ['reason' => 'issuer_mismatch']);
            return false;
        }
        if ($requireEmailVerified && $email !== null && $email !== '') {
            $verified = filter_var($emailVerified, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($verified !== true) {
                Log::warning('StatelessQueue: Google auth rejected', ['reason' => 'email_not_verified']);
                return false;
            }
        }
        if ($allowedServiceAccounts !== [] || $allowedEmailSuffixes !== []) {
            if ($email === null || $email === '') {
                Log::warning('StatelessQueue: Google auth rejected', ['reason' => 'missing_email']);
                return false;
            }
            $exactMatch = in_array($email, $allowedServiceAccounts, true);
            $suffixMatch = false;
            foreach ($allowedEmailSuffixes as $suffix) {
                if (is_string($suffix) && $suffix !== '' && str_ends_with($email, $suffix)) {
                    $suffixMatch = true;
                    break;
                }
            }
            if (! $exactMatch && ! $suffixMatch) {
                Log::warning('StatelessQueue: Google auth rejected', ['reason' => 'email_not_allowlisted']);
                return false;
            }
        }
        return true;
    }

    /** Lazy-initialises and returns the PubSubClient. */
    private function pubSub(): PubSubClient
    {
        if ($this->pubSub === null) {
            $this->pubSub = new PubSubClient([
                'projectId' => $this->config['project_id'],
                'keyFilePath' => $this->config['key_file'],
            ]);
        }

        return $this->pubSub;
    }
}

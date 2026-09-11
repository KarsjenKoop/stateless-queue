<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Webhook authentication and request gating rules.
 *
 * These tests focus on the “can this request proceed?” decision at the webhook boundary, before
 * we attempt payload source detection, decoding, allowlist checks, or job execution.
 *
 * Context:
 * - Requests can come from AWS SNS, Google Pub/Sub push, or local/dev callers.
 * - `stateless-queue.allow_local_unverified` controls whether unsigned local traffic can bypass verification.
 * - `stateless-queue.webhook_secret` supports a query-string bypass (`?secret=...`) for trusted callers.
 *
 * Validates:
 * - Requests are rejected when signature verification is required and no valid auth is provided.
 * - `secret` query param can bypass signature verification when configured.
 * - Invalid AWS/Google auth signals are rejected with a deterministic status + error payload.
 *
 * Does not validate:
 * - Payload parsing/decoding (covered by parsing/validation tests).
 * - Allowed-jobs allowlist behavior (covered by allowlist tests).
 * - Job instantiation/handle() execution (covered by execution tests).
 */
class WebhookAuthenticationTest extends TestCase
{
    /**
     * Ensures the webhook rejects unsigned/unauthenticated requests when local bypass is disabled.
     *
     * Validates:
     * - With `stateless-queue.allow_local_unverified=false`, an otherwise ordinary JSON POST is blocked
     *   by the authentication gate.
     * - The response is a stable 401 with a clear "missing/invalid signature" error message.
     *
     * Payload context:
     * - Body is a minimal JSON object (not a broker payload). This test intentionally does not reach
     *   source detection or dispatch logic.
     *
     * Out of scope:
     * - Any broker-specific authentication (AWS/Google).
     * - Any decoding/allowlist/dispatch behavior.
     */
    public function test_unauthenticated_request_is_rejected_with_401_when_local_bypass_disabled(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', false);

        // When
        $response = $this->postJson(route('stateless.webhook'), ['foo' => 'bar']);

        // Then
        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized: Missing or Invalid Signature']);
    }

    /**
     * Verifies the query-string secret bypass, including negative and positive cases.
     *
     * Validates:
     * - When `allow_local_unverified=false`, a wrong `?secret=` does not bypass authentication (401).
     * - A correct `?secret=` allows the request to pass the auth gate.
     * - After passing the gate, the request proceeds to payload source detection; because the body is
     *   not a recognized broker payload, the controller returns 400 "Unknown payload source".
     *
     * Out of scope:
     * - Broker signature/token verification flows (this bypasses them by design).
     */
    public function test_query_secret_allows_request_when_correct(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', 'super-secret');

        // When/Then: wrong secret -> still unauthorized
        $wrong = $this->postJson(route('stateless.webhook', ['secret' => 'wrong']), ['foo' => 'bar']);
        $wrong->assertStatus(401);

        // When/Then: correct secret -> middleware passes; controller then returns 400 for unknown payload
        $correct = $this->postJson(route('stateless.webhook', ['secret' => 'super-secret']), ['foo' => 'bar']);
        $correct->assertStatus(400)
            ->assertJson(['error' => 'Unknown payload source']);
    }

    /**
     * Confirms that the local “unverified request” bypass is strictly controlled by configuration.
     *
     * Validates:
     * - With `allow_local_unverified=false`, an unsigned request is rejected (401).
     * - With the flag enabled, the same unsigned request is allowed past auth and fails later with
     *   400 "Unknown payload source" (proving the middleware did not block it).
     *
     * Out of scope:
     * - Any job processing; the payload is intentionally not decodable into a job.
     */
    public function test_local_bypass_is_active_only_when_flag_enabled(): void
    {
        // Given/When/Then: when disabled, we should get 401
        config()->set('stateless-queue.allow_local_unverified', false);
        $response = $this->postJson(route('stateless.webhook'), ['foo' => 'bar']);
        $response->assertStatus(401);

        // Given/When/Then: when enabled, the same request should not be blocked by the middleware
        config()->set('stateless-queue.allow_local_unverified', true);
        $bypass = $this->postJson(route('stateless.webhook'), ['foo' => 'bar']);
        $bypass->assertStatus(400)
            ->assertJson(['error' => 'Unknown payload source']);
    }

    /**
     * Ensures SNS-shaped requests with an invalid/missing signature are rejected as forbidden (403).
     *
     * Validates:
     * - When the request is marked as SNS (`x-amz-sns-message-type: Notification`) the AWS verifier runs.
     * - A failing verification yields 403 with a stable "Invalid AWS Signature" error response.
     *
     * Payload context:
     * - Body is a minimal SNS notification shape; signature fields that SNS would normally include are absent
     *   to force the failure path.
     *
     * Out of scope:
     * - Decoding the SNS `Message` into a job and dispatching it.
     */
    public function test_invalid_aws_signature_returns_403_for_aws_sns_notification(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);

        $payload = [
            'Type' => 'Notification',
            'Message' => json_encode(['foo' => 'bar']),
        ];

        // When
        $response = $this->withHeaders(['x-amz-sns-message-type' => 'Notification'])
            ->postJson(route('stateless.webhook'), $payload);

        // Then
        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid AWS Signature']);
    }

    /**
     * Ensures requests presenting an invalid Google bearer token are rejected as forbidden (403).
     *
     * Validates:
     * - A request carrying the Pub/Sub subscription header and an invalid Bearer token triggers
     *   Google auth validation and fails with 403 "Invalid Google Token".
     *
     * Out of scope:
     * - Correct Google Pub/Sub push decoding (this test is about authentication only).
     */
    public function test_invalid_google_token_returns_403_for_google_push(): void
    {
        // Given
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);

        // When — X-Goog-Pubsub-Subscription-Name is what Google always sends; Authorization carries the JWT
        $response = $this->withHeaders([
                'X-Goog-Pubsub-Subscription-Name' => 'projects/my-project/subscriptions/my-sub',
                'Authorization' => 'Bearer invalid-token',
            ])
            ->postJson(route('stateless.webhook'), ['foo' => 'bar']);

        // Then
        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid Google Token']);
    }

    /**
     * Ensures Pub/Sub requests using a non-Bearer auth scheme are rejected (403).
     *
     * Validates:
     * - A request with the Pub/Sub subscription header but a Basic auth scheme fails at
     *   extractBearerToken (no Bearer prefix) and is rejected with 403 "Invalid Google Token".
     */
    public function test_google_push_without_bearer_scheme_returns_403(): void
    {
        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', null);

        $response = $this->withHeaders([
                'X-Goog-Pubsub-Subscription-Name' => 'projects/my-project/subscriptions/my-sub',
                'Authorization' => 'Basic '.base64_encode('x:y'),
            ])
            ->postJson(route('stateless.webhook'), ['foo' => 'bar']);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid Google Token']);
    }
}


<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * The shared-secret fallback in `VerifyWebhookSignature`.
 *
 * `?secret=` is compared against a configured value with hash_equals(). A plain `===` returns as soon
 * as two bytes differ, so the time a rejection takes depends on how many leading bytes were correct.
 * Averaged over enough requests that difference is measurable, and it lets a caller recover the secret
 * one byte at a time instead of brute-forcing the whole thing.
 *
 * Validates:
 * - The exact secret is accepted; anything else is rejected with 401.
 * - Near-misses are rejected — a prefix, an extension, and a same-length one-byte difference. These
 *   are the shapes a byte-at-a-time attack submits, so they must be indistinguishable from any other
 *   wrong value.
 * - Comparison is byte-exact with respect to case.
 * - A non-string query value (`?secret[]=x`) is rejected, not a 500. hash_equals() raises a TypeError
 *   on a non-string, which would turn a failed auth attempt into a server error.
 * - An unset or empty configured secret closes the path entirely rather than accepting an empty value.
 *
 * Does not validate:
 * - Adapter signature verification, which takes precedence over this fallback (covered by
 *   `WebhookAuthenticationTest`).
 * - Actual timing behaviour, which is not observable reliably in a test suite. This pins the
 *   correctness contract; hash_equals() supplies the timing property.
 */
class WebhookSecretComparisonTest extends TestCase
{
    private const SECRET = 'super-secret-webhook-value';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stateless-queue.allow_local_unverified', false);
        config()->set('stateless-queue.webhook_secret', self::SECRET);
    }

    /** Posts a body no adapter claims, so the request always reaches the secret fallback. */
    private function postWithSecret(?string $secret): \Illuminate\Testing\TestResponse
    {
        $url = route('stateless.webhook');

        if ($secret !== null) {
            $url .= '?secret='.urlencode($secret);
        }

        return $this->postJson($url, ['unrecognised' => 'payload']);
    }

    public function test_exact_secret_is_accepted(): void
    {
        // When
        $response = $this->postWithSecret(self::SECRET);

        // Then — past the middleware; no adapter claims the body, so the controller answers 400.
        $response->assertStatus(400)->assertJson(['error' => 'Unknown payload source']);
    }

    /**
     * @return array<string, string>
     */
    private static function nearMisses(): array
    {
        return [
            'prefix of the secret'          => 'super-secret-webhook-valu',
            'one byte too long'             => 'super-secret-webhook-values',
            'same length, last byte differs'=> 'super-secret-webhook-valuX',
            'same length, first byte differs'=> 'Xuper-secret-webhook-value',
            'correct value, wrong case'     => 'SUPER-SECRET-WEBHOOK-VALUE',
            'empty string'                  => '',
            'unrelated value'               => 'nope',
        ];
    }

    public function test_near_miss_secrets_are_rejected(): void
    {
        foreach (self::nearMisses() as $label => $candidate) {
            // When
            $response = $this->postWithSecret($candidate);

            // Then — every wrong value fails identically, whatever its shape.
            $this->assertSame(401, $response->getStatusCode(), "case: {$label}");
            $response->assertJson(['error' => 'Unauthorized: Missing or Invalid Signature']);
        }
    }

    public function test_missing_secret_parameter_is_rejected(): void
    {
        $this->postWithSecret(null)->assertStatus(401);
    }

    public function test_array_secret_parameter_is_rejected_and_does_not_error(): void
    {
        // Given — hash_equals() raises a TypeError on a non-string, which would surface as a 500 and
        // hand a caller a way to tell "wrong secret" apart from "malformed secret".
        $url = route('stateless.webhook').'?secret[]='.urlencode(self::SECRET);

        // When
        $response = $this->postJson($url, ['unrecognised' => 'payload']);

        // Then
        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized: Missing or Invalid Signature']);
    }

    public function test_unset_secret_closes_the_fallback_entirely(): void
    {
        // Given
        config()->set('stateless-queue.webhook_secret', null);

        // Then — including for a request that sends no secret at all.
        $this->postWithSecret(null)->assertStatus(401);
        $this->postWithSecret('')->assertStatus(401);
        $this->postWithSecret('anything')->assertStatus(401);
    }

    /**
     * Documents a framework behaviour this middleware inherits and does not control.
     *
     * Laravel's global `TrimStrings` middleware runs ahead of `stateless.signature` and trims query
     * parameters, so a secret submitted with surrounding whitespace arrives already trimmed and
     * matches. This does not weaken the check — a caller still needs the exact secret — but it means
     * the comparison is byte-exact only over the value the framework hands us.
     *
     * Recorded as a test so the behaviour is a known property rather than a surprise, and so removing
     * `TrimStrings` from an application's stack shows up here as a change rather than silently.
     */
    public function test_surrounding_whitespace_is_trimmed_by_the_framework_before_comparison(): void
    {
        // When
        $response = $this->postWithSecret('  '.self::SECRET.'  ');

        // Then — past the middleware, so the controller answers 400 for the unrecognised body.
        $response->assertStatus(400);
    }

    public function test_empty_configured_secret_does_not_accept_an_empty_query_value(): void
    {
        // Given — an empty configured secret must not become "any empty value authenticates".
        config()->set('stateless-queue.webhook_secret', '');

        // Then
        $this->postWithSecret('')->assertStatus(401);
        $this->postWithSecret(null)->assertStatus(401);
    }
}

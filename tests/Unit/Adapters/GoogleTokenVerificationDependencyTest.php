<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Google\Auth\AccessToken;
use PHPUnit\Framework\TestCase;

/**
 * The inbound Google path depends on phpseclib, and google/auth only suggests it.
 *
 * `AccessToken::verify()` — the call behind every inbound Pub/Sub webhook — needs
 * phpseclib/phpseclib v3 to turn Google's published JWK certs into a key it can verify against.
 * google/auth lists it under `suggest`, not `require`, so without an explicit requirement in this
 * package it simply is not installed, and every verification fails with:
 *
 *     RuntimeException: Please require phpseclib/phpseclib v3 to use this utility.
 *
 * That failure is invisible in the test suite and in local E2E, because both run with
 * `allow_local_unverified` enabled and never reach the verifier. It surfaces only against a real
 * Google-signed token — which is to say, in production, as a blanket 403 on every push.
 *
 * Validates:
 * - phpseclib v3 is installed.
 * - `AccessToken::verify()` reaches a real verification verdict on a well-formed token, rather than
 *   aborting on a missing dependency.
 *
 * Does not validate:
 * - That a genuine Google token verifies, which would need Google's live signing keys.
 * - The claim policy applied afterwards (covered by `GooglePubSubAuthPolicyTest`).
 */
class GoogleTokenVerificationDependencyTest extends TestCase
{
    public function test_phpseclib_v3_is_installed(): void
    {
        $this->assertTrue(
            class_exists(\phpseclib3\Crypt\RSA::class),
            'phpseclib/phpseclib v3 must be a hard requirement: google/auth only suggests it, and '
            .'AccessToken::verify() cannot verify an inbound Pub/Sub token without it.',
        );
    }

    /**
     * A well-formed RS256 token signed by nobody must produce a verdict of "not valid", not an
     * abort *for want of a library*. Those two outcomes are indistinguishable to the adapter — both
     * end in a 403 — which is exactly why the missing dependency went unnoticed.
     *
     * Catches Throwable rather than RuntimeException. How the library rejects a synthetic token
     * varies with its version: the oldest google/auth and firebase/php-jwt this package accepts
     * raise a TypeError from inside their own decoding, which is not a RuntimeException and escaped
     * the narrower catch on the prefer-lowest matrix row. That variation is not what this test is
     * about — only whether the failure is the missing-dependency one — so any throwable is caught
     * and inspected for that single signature.
     */
    public function test_verifier_reaches_a_verdict_instead_of_failing_on_a_missing_dependency(): void
    {
        // Header {"alg":"RS256","kid":"...","typ":"JWT"} + a payload, with a signature that is not
        // Google's. Well-formed enough that the library gets as far as checking the signature.
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","kid":"f10f87405a979c1df36df26606734f33cd85c271","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"aud":"example","iss":"https://accounts.google.com","exp":9999999999}'), '+/', '-_'), '=');
        $token = $header.'.'.$payload.'.'.rtrim(strtr(base64_encode('not-a-real-signature'), '+/', '-_'), '=');

        try {
            $result = (new AccessToken())->verify($token);
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'phpseclib',
                $e->getMessage(),
                'Verification aborted on a missing dependency rather than rejecting the token.',
            );

            return;
        }

        // With the dependency present the library returns false: a real verdict.
        $this->assertFalse($result);
    }
}

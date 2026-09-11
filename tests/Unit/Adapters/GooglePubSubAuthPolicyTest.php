<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * Google Pub/Sub JWT claim policy (adapter-contained).
 *
 * Exercises private validateClaimsAgainstPolicy via reflection — no HTTP.
 * Uses package TestCase so `Log` facade is available when policy rejects claims.
 */
class GooglePubSubAuthPolicyTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $auth
     * @param  array<string, mixed>  $connection
     */
    private function makeAdapter(array $auth, array $connection = []): GooglePubSubAdapter
    {
        return new GooglePubSubAdapter(array_merge([
            'project_id' => 'unit-test-project',
            'key_file' => __DIR__.'/../../dummy-credentials.json',
            'auth' => $auth,
        ], $connection));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(GooglePubSubAdapter $adapter, array $claims): bool
    {
        $method = new \ReflectionMethod(GooglePubSubAdapter::class, 'validateClaimsAgainstPolicy');
        $method->setAccessible(true);

        return $method->invoke($adapter, $claims);
    }

    public function test_rejects_wrong_audience_string(): void
    {
        $adapter = $this->makeAdapter([
            'expected_audience' => 'https://app',
            'allowed_issuers' => [],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertFalse($this->validateClaims($adapter, [
            'aud' => 'https://wrong',
            'iss' => 'https://accounts.google.com',
        ]));
    }

    public function test_accepts_matching_audience_and_allowed_issuer(): void
    {
        $adapter = $this->makeAdapter([
            'expected_audience' => 'https://app',
            'allowed_issuers' => ['https://accounts.google.com'],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertTrue($this->validateClaims($adapter, [
            'aud' => 'https://app',
            'iss' => 'https://accounts.google.com',
        ]));
    }

    public function test_rejects_wrong_issuer_when_allowlist_non_empty(): void
    {
        $adapter = $this->makeAdapter([
            'allowed_issuers' => ['https://accounts.google.com'],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertFalse($this->validateClaims($adapter, [
            'iss' => 'https://evil-issuer.example',
        ]));
    }

    public function test_rejects_unverified_email_when_required(): void
    {
        $adapter = $this->makeAdapter([
            'allowed_issuers' => [],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertFalse($this->validateClaims($adapter, [
            'email' => 'someone@example.com',
            'email_verified' => false,
        ]));
    }

    public function test_rejects_email_not_in_allowed_service_accounts(): void
    {
        $adapter = $this->makeAdapter([
            'allowed_issuers' => [],
            'allowed_service_accounts' => ['allowed@project.iam.gserviceaccount.com'],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertFalse($this->validateClaims($adapter, [
            'email' => 'other@project.iam.gserviceaccount.com',
            'email_verified' => true,
        ]));
    }

    public function test_accepts_email_matching_suffix_allowlist(): void
    {
        $adapter = $this->makeAdapter([
            'allowed_issuers' => [],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => ['@allowed-suffix.example'],
            'require_email_verified' => true,
        ]);

        $this->assertTrue($this->validateClaims($adapter, [
            'email' => 'push-sa@allowed-suffix.example',
            'email_verified' => true,
        ]));
    }

    public function test_rejects_missing_email_when_allowlist_requires_identity(): void
    {
        $adapter = $this->makeAdapter([
            'allowed_issuers' => [],
            'allowed_service_accounts' => ['allowed@project.iam.gserviceaccount.com'],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertFalse($this->validateClaims($adapter, [
            'iss' => 'https://accounts.google.com',
        ]));
    }

    public function test_accepts_expected_audience_when_aud_claim_is_array(): void
    {
        $adapter = $this->makeAdapter([
            'expected_audience' => 'https://app',
            'allowed_issuers' => ['https://accounts.google.com'],
            'allowed_service_accounts' => [],
            'allowed_email_suffixes' => [],
            'require_email_verified' => true,
        ]);

        $this->assertTrue($this->validateClaims($adapter, [
            'aud' => ['https://other', 'https://app'],
            'iss' => 'https://accounts.google.com',
        ]));
    }
}

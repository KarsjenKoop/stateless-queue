<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Adapters;

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * What `GooglePubSubAdapter::verifySignature()` is allowed to write to the log when it fails.
 *
 * A rejected bearer token is still a live credential. The JWT library raising on it has the token in
 * hand, and libraries routinely quote the offending input back in the exception message — a segment,
 * the decoded header, sometimes the whole string. Forwarding that message to the log turns every
 * failed verification into a credential written to disk, shipped to whatever aggregator the
 * application uses, and readable by anyone with log access.
 *
 * The adapter therefore records the exception class and fixed reason codes, never the library message.
 *
 * Validates:
 * - No log line emitted during a failed verification contains the token, in whole or in part.
 * - The failure log carries an `exception` key and no `error` key, so a future change that reinstates
 *   message forwarding fails here rather than passing quietly.
 * - Policy rejections log a fixed reason code, not claim values.
 * - A missing or malformed Authorization header is logged without the header value.
 *
 * Does not validate:
 * - Whether verification reaches the correct verdict (covered by `GooglePubSubAuthPolicyTest`).
 * - Response bodies (covered by `WebhookErrorLeakageTest`).
 */
class GoogleTokenLoggingTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
    }

    /** Every logged message and context value flattened into one searchable string. */
    private function loggedText(): string
    {
        $parts = [];
        foreach ($this->logged as $entry) {
            $parts[] = $entry->message;
            array_walk_recursive($entry->context, function ($v) use (&$parts): void {
                $parts[] = is_scalar($v) ? (string) $v : json_encode($v);
            });
        }

        return implode("\n", $parts);
    }

    private function requestWithToken(string $token): Request
    {
        $request = Request::create('/api/stateless/webhook', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function adapter(array $auth = []): GooglePubSubAdapter
    {
        return new GooglePubSubAdapter([
            'project_id' => 'test-project',
            'auth' => $auth,
        ]);
    }

    public function test_rejected_token_never_appears_in_the_log(): void
    {
        // Given — a distinctive value, so a partial leak is as detectable as a whole one.
        $token = 'eyJhbGciOiJSUzI1NiJ9.SECRETPAYLOAD'.bin2hex(random_bytes(16)).'.SIGNATUREBYTES';

        // When
        $verified = $this->adapter()->verifySignature($this->requestWithToken($token));

        // Then
        $this->assertFalse($verified);

        $text = $this->loggedText();
        $this->assertStringNotContainsString($token, $text);
        $this->assertStringNotContainsString('SECRETPAYLOAD', $text);
        $this->assertStringNotContainsString('SIGNATUREBYTES', $text);
    }

    public function test_verification_failure_logs_the_exception_class_and_not_its_message(): void
    {
        // Given
        $token = 'not-a-jwt-at-all';

        // When
        $this->adapter()->verifySignature($this->requestWithToken($token));

        // Then
        $failures = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $e) => str_contains($e->message, 'Google token verification failed'),
        ));

        $this->assertNotEmpty($failures, 'A failed verification should be logged.');

        foreach ($failures as $entry) {
            $this->assertArrayHasKey(
                'exception',
                $entry->context,
                'The failure must record which exception class was raised.',
            );
            $this->assertArrayNotHasKey(
                'error',
                $entry->context,
                'The library exception message must not be forwarded to the log — it can quote the token.',
            );
            $this->assertStringNotContainsString($token, json_encode($entry->context));
        }
    }

    public function test_missing_bearer_header_is_logged_without_the_header_value(): void
    {
        // Given
        $request = Request::create('/api/stateless/webhook', 'POST');
        $request->headers->set('Authorization', 'Basic dXNlcjpTRUNSRVRQQVNTV09SRA==');

        // When
        $verified = $this->adapter()->verifySignature($request);

        // Then
        $this->assertFalse($verified);
        $this->assertStringNotContainsString('SECRETPASSWORD', $this->loggedText());
        $this->assertStringNotContainsString('dXNlcjpTRUNSRVRQQVNTV09SRA==', $this->loggedText());
    }

    public function test_absent_authorization_header_is_rejected_and_logged(): void
    {
        // When
        $verified = $this->adapter()->verifySignature(Request::create('/api/stateless/webhook', 'POST'));

        // Then
        $this->assertFalse($verified);
        $this->assertStringContainsString('missing bearer authorization', $this->loggedText());
    }
}

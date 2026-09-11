<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Exceptions\WebhookParseException;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use Karsjen\StatelessQueue\Tests\Support\Adapters\ThrowingInboundAdapter;
use Karsjen\StatelessQueue\Tests\Support\Jobs\LeakyExceptionJob;
use Karsjen\StatelessQueue\Tests\Support\WebhookPayloadBuilder;
use Karsjen\StatelessQueue\Tests\TestCase;
use RuntimeException;

/**
 * The webhook must not return internal exception text to its caller.
 *
 * The endpoint is public by necessity — a cloud provider has to reach it — so its response body is
 * readable by anyone who can POST to the URL, including a caller whose request was rejected. Exception
 * messages routinely carry deployment paths, connection strings, SQL fragments, and class names, all of
 * which are reconnaissance for an attacker probing the endpoint.
 *
 * Every failure response body is therefore a fixed string chosen by the controller. The detail goes to
 * the log, where operators can reach it and callers cannot.
 *
 * Validates:
 * - A job that throws with internal detail in its message leaks none of it to the response.
 * - An adapter that throws unexpectedly leaks none of its message to the response.
 * - A parse failure leaks none of its message to the response.
 * - An allowlist denial does not echo the submitted class name back, which would let a caller
 *   enumerate which classes are executable.
 * - The detail is still written to the log, so genericising the response did not lose observability.
 *
 * Does not validate:
 * - Which status each failure maps to (covered by `WebhookErrorClassificationTest`).
 * - Credential redaction in log context (covered by `WebhookSecurityLoggingTest`).
 */
class WebhookErrorLeakageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ThrowingInboundAdapter::$toThrow = null;
        config()->set('stateless-queue.allow_local_unverified', true);
    }

    protected function tearDown(): void
    {
        ThrowingInboundAdapter::$toThrow = null;

        parent::tearDown();
    }

    private function useThrowingAdapter(): void
    {
        $this->app->singleton(ProviderRegistry::class, fn ($app) => new ProviderRegistry(
            $app,
            [ThrowingInboundAdapter::class],
        ));
    }

    public function test_job_exception_detail_never_reaches_the_response_body(): void
    {
        // Given
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        // When
        $response = $this->withoutMiddleware()->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush([
            'uuid' => 'leaky-1',
            'job_class' => LeakyExceptionJob::class,
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ]));

        // Then
        $response->assertStatus(500)->assertJson(['error' => 'Job execution failed']);

        $body = $response->getContent();
        foreach (LeakyExceptionJob::SECRETS as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                'Internal exception detail must never be returned to the webhook caller.',
            );
        }
    }

    public function test_unexpected_adapter_exception_detail_never_reaches_the_response_body(): void
    {
        // Given
        $this->useThrowingAdapter();
        ThrowingInboundAdapter::$toThrow = new RuntimeException('/etc/secrets/gcp-key.json is unreadable');

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then
        $response->assertStatus(500)->assertJson(['error' => 'Failed to process webhook payload']);
        $this->assertStringNotContainsString('/etc/secrets/gcp-key.json', $response->getContent());
    }

    public function test_parse_exception_detail_never_reaches_the_response_body(): void
    {
        // Given
        $this->useThrowingAdapter();
        ThrowingInboundAdapter::$toThrow = WebhookParseException::invalidJson(
            'Syntax error at offset 42 near "internal-topic-name"',
        );

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then
        $response->assertStatus(422)->assertJson(['error' => 'Invalid payload structure']);
        $this->assertStringNotContainsString('internal-topic-name', $response->getContent());
        $this->assertStringNotContainsString('offset 42', $response->getContent());
    }

    public function test_allowlist_denial_does_not_echo_the_submitted_class_name(): void
    {
        // Given — echoing the class name back turns the endpoint into an oracle: a caller could submit
        // candidate class names and read off which ones the allowlist accepts.
        config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

        // When
        $response = $this->withoutMiddleware()->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush([
            'uuid' => 'probe-1',
            'job_class' => LeakyExceptionJob::class,
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ]));

        // Then
        $response->assertStatus(403)->assertJson(['error' => 'Job class not allowed']);
        $this->assertStringNotContainsString(LeakyExceptionJob::class, $response->getContent());
        $this->assertStringNotContainsString('LeakyExceptionJob', $response->getContent());
        $this->assertStringNotContainsString('allowed_jobs', $response->getContent());
    }

    public function test_detail_removed_from_the_response_is_still_written_to_the_log(): void
    {
        // Given — genericising the response must not cost operators their debugging information.
        config()->set('stateless-queue.allowed_jobs', ['Karsjen\\StatelessQueue\\Tests\\*']);

        $logged = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function (\Illuminate\Log\Events\MessageLogged $e) use (&$logged): void {
                // Flatten the raw context values rather than JSON-encoding them, so paths and
                // class names match as written instead of in their escaped form.
                $flat = array_map(
                    fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
                    $e->context,
                );
                $logged[] = $e->message.' '.implode(' ', $flat);
            },
        );

        // When
        $this->withoutMiddleware()->postJson(route('stateless.webhook'), WebhookPayloadBuilder::googlePush([
            'uuid' => 'leaky-2',
            'job_class' => LeakyExceptionJob::class,
            'topic' => 'test-topic',
            'payload' => ['message' => 'x'],
            'timestamp' => time(),
        ]));

        // Then
        $all = implode("\n", $logged);
        $this->assertStringContainsString(
            LeakyExceptionJob::SECRETS[0],
            $all,
            'The exception detail withheld from the response must still be logged.',
        );
        $this->assertStringContainsString(LeakyExceptionJob::class, $all);
    }
}

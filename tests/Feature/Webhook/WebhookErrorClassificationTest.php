<?php

namespace Karsjen\StatelessQueue\Tests\Feature\Webhook;

use Karsjen\StatelessQueue\Exceptions\WebhookParseException;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use Karsjen\StatelessQueue\Tests\Support\Adapters\ThrowingInboundAdapter;
use Karsjen\StatelessQueue\Tests\TestCase;
use RuntimeException;

/**
 * How the controller classifies a failure thrown out of an adapter's parseRequest().
 *
 * The controller distinguishes a caller fault from an internal fault by exception *type*, not by
 * matching on the exception's message text. Message matching is brittle: rewording an exception, or
 * an unrelated exception that happens to contain the same words, silently changes the HTTP status
 * the provider sees — and the status is what drives its retry and dead-letter behaviour.
 *
 * Validates:
 * - A `WebhookParseException` is a caller fault -> 422.
 * - Any other throwable is an internal fault -> 500.
 * - The classification is driven by type, so a non-parse exception whose message contains the words
 *   "invalid payload structure" is still classified as internal.
 * - A `WebhookParseException` whose message shares no words with the generic response is still 422.
 *
 * Does not validate:
 * - Which exception a real adapter throws for a given payload (covered by
 *   `WebhookJobDispatchValidationTest`).
 * - Response body wording (covered by `WebhookErrorLeakageTest`).
 */
class WebhookErrorClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ThrowingInboundAdapter::$toThrow = null;
        config()->set('stateless-queue.allow_local_unverified', true);

        // Replace the registry so the throwing adapter owns every request.
        $this->app->singleton(ProviderRegistry::class, fn ($app) => new ProviderRegistry(
            $app,
            [ThrowingInboundAdapter::class],
        ));
    }

    protected function tearDown(): void
    {
        ThrowingInboundAdapter::$toThrow = null;

        parent::tearDown();
    }

    public function test_webhook_parse_exception_is_classified_as_a_caller_fault_with_422(): void
    {
        // Given
        ThrowingInboundAdapter::$toThrow = WebhookParseException::invalidStructure();

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then
        $response->assertStatus(422);
    }

    public function test_parse_exception_is_classified_by_type_not_by_message_wording(): void
    {
        // Given — a parse failure whose message shares no wording with the generic response body.
        ThrowingInboundAdapter::$toThrow = WebhookParseException::invalidJson('Syntax error');

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then — still 422, because the type is what decides.
        $response->assertStatus(422);
    }

    public function test_unexpected_adapter_failure_is_classified_as_an_internal_fault_with_500(): void
    {
        // Given
        ThrowingInboundAdapter::$toThrow = new RuntimeException('SDK connection reset');

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then
        $response->assertStatus(500);
    }

    public function test_non_parse_exception_is_not_downgraded_by_message_text(): void
    {
        // Given — the exact phrase the old string-matching classifier keyed on, on an exception type
        // that is *not* a parse failure. Under message matching this returned 422; it is a 500.
        ThrowingInboundAdapter::$toThrow = new RuntimeException('Invalid payload structure');

        // When
        $response = $this->postJson(route('stateless.webhook'), ['anything' => true]);

        // Then
        $response->assertStatus(500);
    }
}

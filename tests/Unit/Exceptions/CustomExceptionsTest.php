<?php

namespace Karsjen\StatelessQueue\Tests\Unit\Exceptions;

use Karsjen\StatelessQueue\Exceptions\AdapterPublishException;
use Karsjen\StatelessQueue\Exceptions\JobNotAllowedException;
use Karsjen\StatelessQueue\Exceptions\WebhookParseException;
use Karsjen\StatelessQueue\Tests\TestCase;
use RuntimeException;

/**
 * Named constructor coverage for the three domain-specific exception classes.
 *
 * Validates:
 * - Each named constructor produces the correct exception type and message.
 * - Previous throwables are correctly chained for AdapterPublishException and WebhookParseException.
 *
 * Does not validate:
 * - Where these exceptions are thrown (covered by integration tests in the adapter and webhook suites).
 */
class CustomExceptionsTest extends TestCase
{
    // --- JobNotAllowedException ---

    public function test_not_in_allowlist_produces_correct_message(): void
    {
        $e = JobNotAllowedException::notInAllowlist('App\\Jobs\\MyJob');

        $this->assertInstanceOf(JobNotAllowedException::class, $e);
        $this->assertStringContainsString('App\\Jobs\\MyJob', $e->getMessage());
        $this->assertStringContainsString('allowed_jobs', $e->getMessage());
    }

    public function test_job_not_allowed_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, JobNotAllowedException::notInAllowlist('Foo'));
    }

    // --- WebhookParseException ---

    public function test_invalid_structure_produces_default_message(): void
    {
        $e = WebhookParseException::invalidStructure();

        $this->assertInstanceOf(WebhookParseException::class, $e);
        $this->assertSame('Invalid payload structure', $e->getMessage());
    }

    public function test_invalid_structure_accepts_custom_detail(): void
    {
        $e = WebhookParseException::invalidStructure('Missing job_class field');

        $this->assertSame('Missing job_class field', $e->getMessage());
    }

    public function test_invalid_json_includes_detail_and_chains_previous(): void
    {
        $previous = new \RuntimeException('json error');
        $e = WebhookParseException::invalidJson('unexpected token', $previous);

        $this->assertInstanceOf(WebhookParseException::class, $e);
        $this->assertStringContainsString('unexpected token', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_not_an_object_produces_correct_message(): void
    {
        $e = WebhookParseException::notAnObject();

        $this->assertInstanceOf(WebhookParseException::class, $e);
        $this->assertStringContainsString('not a JSON object', $e->getMessage());
    }

    public function test_webhook_parse_exception_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, WebhookParseException::invalidStructure());
    }

    // --- AdapterPublishException ---

    public function test_for_adapter_includes_adapter_name_and_message(): void
    {
        $e = AdapterPublishException::forAdapter('aws', 'connection refused');

        $this->assertInstanceOf(AdapterPublishException::class, $e);
        $this->assertStringContainsString('aws', $e->getMessage());
        $this->assertStringContainsString('connection refused', $e->getMessage());
    }

    public function test_for_adapter_chains_previous_throwable(): void
    {
        $previous = new \RuntimeException('underlying error');
        $e = AdapterPublishException::forAdapter('google', 'publish failed', $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_adapter_publish_exception_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, AdapterPublishException::forAdapter('null', 'err'));
    }
}

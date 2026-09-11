<?php

namespace Karsjen\StatelessQueue\Parsing;

use Karsjen\StatelessQueue\Messages\IncomingJobMessage;

/**
 * Value Object: ParseResult
 *
 * Encapsulates the outcome of an adapter's attempt to parse an incoming webhook request.
 *
 * An adapter's parseRequest() always returns one of four kinds:
 * - `job`       — a valid IncomingJobMessage was extracted; pass to JobRunner.
 * - `handshake` — a provider lifecycle event (e.g. SNS SubscriptionConfirmation); respond 200 and stop.
 * - `ignore`    — a recognised but unactionable message type; respond 200 and stop.
 * - `invalid`   — the payload was malformed; respond 400.
 *
 * The httpStatus and httpBody fields carry ready-to-use HTTP response values for the controller.
 *
 * @see \Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter
 * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
 */
final class ParseResult
{
    private function __construct(
        public readonly string $kind,
        public readonly ?IncomingJobMessage $message = null,
        public readonly int $httpStatus = 200,
        public readonly array $httpBody = ['status' => 'handled'],
        public readonly ?string $error = null,
    ) {}

    /** A verified, parseable job payload ready for the JobRunner. */
    public static function job(IncomingJobMessage $message): self
    {
        return new self(kind: 'job', message: $message, httpStatus: 200, httpBody: ['status' => 'success']);
    }

    /** A provider lifecycle message (e.g. subscription confirmation) that requires no job execution. */
    public static function handshake(array $body = ['status' => 'handled']): self
    {
        return new self(kind: 'handshake', httpStatus: 200, httpBody: $body);
    }

    /** A recognised but unactionable message — acknowledged with 200 and discarded. */
    public static function ignore(): self
    {
        return new self(kind: 'ignore', httpStatus: 200, httpBody: ['status' => 'ignored']);
    }

    /**
     * A malformed or unrecognisable payload that should be rejected with a 400 response.
     *
     * The `$error` string is returned to the caller verbatim in the response body, so it must be a
     * fixed, adapter-authored description — never provider payload content or exception text.
     */
    public static function invalid(string $error): self
    {
        return new self(kind: 'invalid', httpStatus: 400, httpBody: ['error' => $error], error: $error);
    }

    /** Returns true only when the result carries a job message ready for execution. */
    public function isJob(): bool
    {
        return $this->kind === 'job' && $this->message !== null;
    }
}

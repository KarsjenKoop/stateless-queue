<?php

namespace Karsjen\StatelessQueue\Tests\Support\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;
use RuntimeException;

/**
 * Fixture: throws an exception whose message contains the kind of internal detail a real failure
 * leaks — a filesystem path, a connection string with a password, and an SQL fragment.
 *
 * Stands in for the exceptions applications actually throw (PDOException, filesystem errors, HTTP
 * client errors), so the controller can be proven not to forward any of it to the caller.
 */
final class LeakyExceptionJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    /** Distinctive substrings that must never appear in an HTTP response body. */
    public const SECRETS = [
        '/var/www/releases/2026-09-04/app/Domain/Billing.php',
        'pgsql://admin:hunter2@10.0.0.7:5432/production',
        'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'prod.invoices\'',
    ];

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void
    {
        throw new RuntimeException(implode(' | ', self::SECRETS));
    }
}

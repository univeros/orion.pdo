<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

use function getenv;

/**
 * Runs when ORION_PGSQL_DSN is set.
 */
final class PostgresReceiptOrderingTest extends ReceiptOrdering
{
    protected function setUp(): void
    {
        if (getenv('ORION_PGSQL_DSN') === false) {
            self::markTestSkipped('ORION_PGSQL_DSN is not set.');
        }
    }

    protected function connect(): PDO
    {
        return new PDO((string) getenv('ORION_PGSQL_DSN'));
    }

    protected function limitLockWait(PDO $pdo): void
    {
        $pdo->exec("SET lock_timeout = '200ms'");
    }
}

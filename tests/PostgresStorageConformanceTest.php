<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

use function getenv;

/**
 * Runs when ORION_PGSQL_DSN is set, e.g. `pgsql:host=127.0.0.1;port=5432;dbname=orion;user=orion;password=orion`.
 */
final class PostgresStorageConformanceTest extends PdoStorageConformance
{
    protected function setUp(): void
    {
        if (getenv('ORION_PGSQL_DSN') === false) {
            self::markTestSkipped('ORION_PGSQL_DSN is not set.');
        }
        parent::setUp();
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

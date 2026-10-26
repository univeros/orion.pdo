<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

use function getenv;

/**
 * Runs when ORION_MYSQL_DSN is set, with ORION_MYSQL_USER and ORION_MYSQL_PASSWORD.
 */
final class MysqlReceiptOrderingTest extends ReceiptOrdering
{
    protected function setUp(): void
    {
        if (getenv('ORION_MYSQL_DSN') === false) {
            self::markTestSkipped('ORION_MYSQL_DSN is not set.');
        }
    }

    protected function connect(): PDO
    {
        return new PDO((string) getenv('ORION_MYSQL_DSN'), (string) getenv('ORION_MYSQL_USER'), (string) getenv('ORION_MYSQL_PASSWORD'));
    }

    protected function limitLockWait(PDO $pdo): void
    {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    }
}

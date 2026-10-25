<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

use function getenv;

/**
 * Runs when ORION_MYSQL_DSN is set, e.g. `mysql:host=127.0.0.1;port=3306;dbname=orion`, with ORION_MYSQL_USER and
 * ORION_MYSQL_PASSWORD.
 */
final class MysqlStorageConformanceTest extends PdoStorageConformance
{
    protected function setUp(): void
    {
        if (getenv('ORION_MYSQL_DSN') === false) {
            self::markTestSkipped('ORION_MYSQL_DSN is not set.');
        }
        parent::setUp();
    }

    protected function connect(): PDO
    {
        return new PDO((string) getenv('ORION_MYSQL_DSN'), (string) getenv('ORION_MYSQL_USER'), (string) getenv('ORION_MYSQL_PASSWORD'));
    }
}

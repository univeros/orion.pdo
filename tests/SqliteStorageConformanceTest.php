<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

final class SqliteStorageConformanceTest extends PdoStorageConformance
{
    protected function connect(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

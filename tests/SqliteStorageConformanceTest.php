<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use PDO;

use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * On a database file rather than `:memory:`, so a second connection sees the same database and its locks.
 */
final class SqliteStorageConformanceTest extends PdoStorageConformance
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'orion-sqlite-');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    protected function connect(): PDO
    {
        return new PDO('sqlite:' . $this->file);
    }

    protected function limitLockWait(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 0);
    }
}

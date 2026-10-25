<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Orion\Pdo\Installer;
use Orion\Pdo\PdoStorage;
use Orion\Storage\Storage;
use Orion\Testing\StorageConformanceTestCase;
use PDO;
use Throwable;

use function array_fill;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Runs the storage conformance case against {@see PdoStorage} on one PDO connection, which plays the host's: the
 * entity rows and the host transaction go through that PDO directly, as an application's own code would.
 */
abstract class PdoStorageConformance extends StorageConformanceTestCase
{
    private PDO $pdo;

    abstract protected function connect(): PDO;

    protected function createStorage(): Storage
    {
        $this->pdo = $this->connect();
        Installer::uninstall($this->pdo, self::tables());
        Installer::install($this->pdo, self::tables());

        return new PdoStorage($this->pdo);
    }

    protected function insertOnHostConnection(string $table, array $row): void
    {
        $quote = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %1$s%2$s%1$s (%3$s) VALUES (%4$s)',
            $quote,
            $table,
            implode(', ', array_map(static fn(string $column): string => $quote . $column . $quote, array_keys($row))),
            implode(', ', array_fill(0, count($row), '?')),
        ));
        $statement->execute(array_map(
            static fn(mixed $value): mixed => $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')
                : $value,
            array_values($row),
        ));
    }

    protected function inHostTransaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }
}

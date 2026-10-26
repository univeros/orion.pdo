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
use Orion\Storage\Schema;
use PDO;
use PDOException;
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

    /**
     * Makes a second connection give up quickly on a locked row instead of waiting the database's default.
     */
    abstract protected function limitLockWait(PDO $pdo): void;

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

    public function testTwoStoragesOnOneConnectionNestTheirSavepoints(): void
    {
        $other = new PdoStorage($this->pdo);
        $storage = new PdoStorage($this->pdo);
        $thrown = null;
        $this->inHostTransaction(function () use ($storage, $other, &$thrown): void {
            try {
                $storage->transaction(function () use ($storage, $other): void {
                    $storage->insert(self::ENTITY, ['id' => 'outer', 'name' => null, 'position' => 1, 'created_at' => new DateTimeImmutable()]);
                    $other->transaction(fn() => $other->insert(self::ENTITY, ['id' => 'inner', 'name' => null, 'position' => 2, 'created_at' => new DateTimeImmutable()]));

                    throw new \RuntimeException('outer failed');
                });
            } catch (\RuntimeException $exception) {
                $thrown = $exception;
            }
        });

        self::assertSame('outer failed', $thrown?->getMessage());
        self::assertSame(0, $storage->count(self::ENTITY, []));
    }

    public function testAnIncrementHoldsItsRowUntilTheTransactionEnds(): void
    {
        $storage = new PdoStorage($this->pdo);
        $storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 0]);
        $second = $this->connect();
        $this->limitLockWait($second);
        $other = new PdoStorage($second);
        $this->inHostTransaction(function () use ($storage, $other): void {
            self::assertSame(1, $storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
            $refused = null;
            try {
                $other->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value');
            } catch (Throwable $exception) {
                $refused = $exception;
            }
            self::assertInstanceOf(PDOException::class, $refused, 'A second connection incremented a row another transaction holds.');
        });

        self::assertSame(2, $other->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
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

<?php

declare(strict_types=1);

namespace Orion\Pdo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Orion\Storage\Condition;
use Orion\Storage\Identifier;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Override;
use Orion\Storage\DuplicateKey;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function array_fill;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function sprintf;
use function str_contains;

/**
 * {@see Storage} over the host's PDO connection. Identifiers are checked and quoted, every value is bound, dates go
 * in as UTC. `transaction()` joins a transaction the host already opened (as a savepoint) instead of starting a
 * second one, so the outbox row commits or rolls back with the host's entity write.
 */
final class PdoStorage implements Storage
{
    private static int $savepoints = 0;

    private readonly Dialect $dialect;

    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('The PDO connection must use PDO::ERRMODE_EXCEPTION; Orion does not reconfigure the host\'s connection.');
        }
        $this->dialect = Dialect::of($pdo);
    }

    #[Override]
    public function findOne(string $table, array $criteria): ?array
    {
        return $this->findMany($table, $criteria, null, 1)[0] ?? null;
    }

    #[Override]
    public function findMany(string $table, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        [$where, $params] = $this->where($criteria);
        $sql = sprintf('SELECT * FROM %s%s', $this->identifier($table), $where);
        if ($orderBy !== null && $orderBy !== []) {
            $order = [];
            foreach ($orderBy as $column => $direction) {
                if ($direction !== 'asc' && $direction !== 'desc') {
                    throw new InvalidArgumentException(sprintf('An order direction is "asc" or "desc", not %s.', Assert::show($direction)));
                }
                $order[] = $this->identifier($column) . ($direction === 'desc' ? ' DESC' : ' ASC');
            }
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }
        $sql .= $this->page($limit, $offset);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    #[Override]
    public function insert(string $table, array $row): void
    {
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($table),
            implode(', ', array_map($this->identifier(...), array_keys($row))),
            implode(', ', array_fill(0, count($row), '?')),
        );
        try {
            $this->run($sql, array_values($row));
        } catch (PDOException $exception) {
            throw self::isDuplicate($exception) ? new DuplicateKey(sprintf('%s already holds a row with that key.', $table), 0, $exception) : $exception;
        }
    }

    /**
     * Unique violations only: PostgreSQL's 23505, MySQL's 1062, SQLite's UNIQUE and PRIMARY KEY failures; any other
     * integrity error (a NOT NULL, say) is not a race and stays what it is.
     */
    private static function isDuplicate(PDOException $exception): bool
    {
        $info = $exception->errorInfo ?? [];

        return match ((string) ($info[0] ?? '')) {
            '23505' => true,
            '23000' => (int) ($info[1] ?? 0) === 1062 || str_contains((string) ($info[2] ?? ''), 'UNIQUE constraint failed'),
            default => false,
        };
    }

    #[Override]
    public function update(string $table, array $criteria, array $data): int
    {
        $this->assertBounded('update', $criteria);
        $set = array_map(fn(string $column): string => $this->identifier($column) . ' = ?', array_keys($data));
        [$where, $params] = $this->where($criteria);
        $sql = sprintf('UPDATE %s SET %s%s', $this->identifier($table), implode(', ', $set), $where);
        if ($this->dialect !== Dialect::Mysql) {
            return $this->run($sql, [...array_values($data), ...$params])->rowCount();
        }

        // MySQL reports changed rows, not matched ones, and the host's connection is not ours to set FOUND_ROWS on.
        return $this->transaction(function () use ($table, $criteria, $sql, $data, $params): int {
            $matched = $this->count($table, $criteria);
            $this->run($sql, [...array_values($data), ...$params]);

            return $matched;
        });
    }

    #[Override]
    public function delete(string $table, array $criteria): int
    {
        $this->assertBounded('delete', $criteria);
        [$where, $params] = $this->where($criteria);

        return $this->run(sprintf('DELETE FROM %s%s', $this->identifier($table), $where), $params)->rowCount();
    }

    #[Override]
    public function count(string $table, array $criteria): int
    {
        [$where, $params] = $this->where($criteria);

        return (int) $this->run(sprintf('SELECT COUNT(*) FROM %s%s', $this->identifier($table), $where), $params)->fetchColumn();
    }

    #[Override]
    public function increment(string $table, array $criteria, string $column): ?int
    {
        $this->assertBounded('increment', $criteria);
        [$where, $params] = $this->where($criteria);
        $quotedTable = $this->identifier($table);
        $quoted = $this->identifier($column);

        // The UPDATE takes the row lock and holds it to the end of the transaction; reading the row back after it
        // sees this transaction's own write on every dialect, MySQL's repeatable read included.
        return $this->transaction(function () use ($quotedTable, $quoted, $where, $params): ?int {
            $matched = $this->run(sprintf('UPDATE %s SET %s = %s + 1%s', $quotedTable, $quoted, $quoted, $where), $params)->rowCount();
            if ($matched > 1) {
                throw new LogicException(sprintf('An increment changes one row; %d match.', $matched));
            }

            return $matched === 0 ? null : (int) $this->run(sprintf('SELECT %s FROM %s%s', $quoted, $quotedTable, $where), $params)->fetchColumn();
        });
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            try {
                $result = $fn();
                $this->pdo->commit();

                return $result;
            } catch (Throwable $exception) {
                $this->quietly($this->rollBackIfOpen(...));

                throw $exception;
            }
        }

        // Process-wide, so two storages (or two Orion graphs) on one connection never reuse a savepoint name.
        $savepoint = 'orion_savepoint_' . ++self::$savepoints;
        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $fn();
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

            return $result;
        } catch (Throwable $exception) {
            $this->quietly(fn() => $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint));

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{string, list<mixed>} the WHERE clause with its leading space, and its parameters
     */
    private function where(array $criteria): array
    {
        if ($criteria === []) {
            return ['', []];
        }
        $clauses = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $quoted = $this->identifier($column);
            if ($value instanceof Condition) {
                if ($value->operator === Condition::NOT_NULL) {
                    $clauses[] = $quoted . ' IS NOT NULL';
                } elseif ($value->value === null) {
                    $clauses[] = '1 = 0';
                } else {
                    $clauses[] = sprintf('%s %s ?', $quoted, $value->operator === Condition::NE ? '<>' : $value->operator);
                    $params[] = $value->value;
                }
            } elseif (is_array($value)) {
                $values = array_values($value);
                $clauses[] = $values === [] ? '1 = 0' : sprintf('%s IN (%s)', $quoted, implode(', ', array_fill(0, count($values), '?')));
                $params = [...$params, ...$values];
            } elseif ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
            } else {
                $clauses[] = $quoted . ' = ?';
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function page(?int $limit, ?int $offset): string
    {
        if (($limit ?? 0) < 0 || ($offset ?? 0) < 0) {
            throw new InvalidArgumentException('A limit or offset cannot be negative.');
        }
        if ($limit === null && $offset === null) {
            return '';
        }
        // MySQL and SQLite need a LIMIT before an OFFSET; PostgreSQL takes OFFSET alone.
        $unbounded = match ($this->dialect) {
            Dialect::Mysql => ' LIMIT 18446744073709551615',
            Dialect::Sqlite => ' LIMIT -1',
            Dialect::Postgres => '',
        };

        return ($limit !== null ? ' LIMIT ' . $limit : $unbounded) . ($offset !== null ? ' OFFSET ' . $offset : '');
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function assertBounded(string $operation, array $criteria): void
    {
        if ($criteria === []) {
            throw new InvalidArgumentException(sprintf('An %s needs criteria; an unbounded one is refused.', $operation));
        }
    }

    /**
     * Runs a rollback without letting its own failure hide the exception that caused it.
     */
    private function quietly(callable $rollback): void
    {
        try {
            $rollback();
        } catch (Throwable) {
        }
    }

    /**
     * The callable may have ended the transaction itself, or the commit may have failed after doing so.
     */
    private function rollBackIfOpen(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function identifier(string $name): string
    {
        return $this->dialect->quote(Identifier::assert($name));
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, ...self::bind($value));
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @return array{mixed, int} the value and its PDO parameter type
     */
    private static function bind(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            $value instanceof DateTimeInterface => [self::utc($value), PDO::PARAM_STR],
            default => [$value, PDO::PARAM_STR],
        };
    }

    private static function utc(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

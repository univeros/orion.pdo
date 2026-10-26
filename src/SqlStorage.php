<?php

declare(strict_types=1);

namespace Orion\Pdo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Orion\Storage\Condition;
use Orion\Storage\DuplicateKey;
use Orion\Storage\Identifier;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Override;
use Throwable;

use function array_fill;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function reset;
use function sprintf;

/**
 * {@see Storage} as SQL, on whatever runs it on the host's connection ({@see Executor}). Identifiers are checked and
 * quoted, every value is bound, dates go in as UTC with microseconds. One SQL for every connection, so a PDO handle
 * and an ORM's connection behave alike, which the storage conformance case proves for each.
 */
final class SqlStorage implements Storage
{
    private readonly Dialect $dialect;

    public function __construct(private readonly Executor $executor)
    {
        $this->dialect = $executor->dialect();
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

        return $this->executor->select($sql . $this->page($limit, $offset), $params);
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
            $this->executor->execute($sql, self::plain(array_values($row)));
        } catch (Throwable $failure) {
            throw $this->executor->isDuplicate($failure) ? new DuplicateKey(sprintf('%s already holds a row with that key.', $table), 0, $failure) : $failure;
        }
    }

    #[Override]
    public function update(string $table, array $criteria, array $data): int
    {
        $this->assertBounded('update', $criteria);
        $set = array_map(fn(string $column): string => $this->identifier($column) . ' = ?', array_keys($data));
        [$where, $params] = $this->where($criteria);
        $sql = sprintf('UPDATE %s SET %s%s', $this->identifier($table), implode(', ', $set), $where);
        $values = [...self::plain(array_values($data)), ...$params];
        if ($this->dialect !== Dialect::Mysql) {
            return $this->executor->execute($sql, $values);
        }

        // MySQL reports changed rows, not matched ones, and the host's connection is not ours to set FOUND_ROWS on. The
        // rows are counted with a locking read: it sees the latest committed state, as the UPDATE does, and not the
        // transaction's snapshot, so a compare-and-set another transaction already won counts as no match.
        return $this->transaction(function () use ($table, $criteria, $sql, $values): int {
            [$where, $params] = $this->where($criteria);
            $locked = sprintf('SELECT COUNT(*) AS %s FROM %s%s FOR UPDATE', $this->dialect->quote('n'), $this->identifier($table), $where);
            $matched = (int) self::first($this->executor->select($locked, $params));
            $this->executor->execute($sql, $values);

            return $matched;
        });
    }

    #[Override]
    public function delete(string $table, array $criteria): int
    {
        $this->assertBounded('delete', $criteria);
        [$where, $params] = $this->where($criteria);

        return $this->executor->execute(sprintf('DELETE FROM %s%s', $this->identifier($table), $where), $params);
    }

    #[Override]
    public function count(string $table, array $criteria): int
    {
        [$where, $params] = $this->where($criteria);

        return (int) self::first($this->executor->select(sprintf('SELECT COUNT(*) AS %s FROM %s%s', $this->dialect->quote('n'), $this->identifier($table), $where), $params));
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
            $matched = $this->executor->execute(sprintf('UPDATE %s SET %s = %s + 1%s', $quotedTable, $quoted, $quoted, $where), $params);
            if ($matched > 1) {
                throw new LogicException(sprintf('An increment changes one row; %d match.', $matched));
            }

            return $matched === 0 ? null : (int) self::first($this->executor->select(sprintf('SELECT %s FROM %s%s', $quoted, $quotedTable, $where), $params));
        });
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        return $this->executor->transaction($fn);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{string, list<string|int|bool|null>} the WHERE clause with its leading space, and its parameters
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
                    $params[] = self::value($value->value);
                }
            } elseif (is_array($value)) {
                $values = array_values($value);
                $clauses[] = $values === [] ? '1 = 0' : sprintf('%s IN (%s)', $quoted, implode(', ', array_fill(0, count($values), '?')));
                $params = [...$params, ...self::plain($values)];
            } elseif ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
            } else {
                $clauses[] = $quoted . ' = ?';
                $params[] = self::value($value);
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

    private function identifier(string $name): string
    {
        return $this->dialect->quote(Identifier::assert($name));
    }

    /**
     * @param list<mixed> $values
     * @return list<string|int|bool|null>
     */
    private static function plain(array $values): array
    {
        return array_map(self::value(...), $values);
    }

    /**
     * What every connection binds alike: dates as UTC with microseconds, floats as their exact text, the rest as is.
     */
    private static function value(mixed $value): string|int|bool|null
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            is_float($value) => (string) $value,
            default => throw new InvalidArgumentException(sprintf('Storage values are scalars, null or dates, not %s.', get_debug_type($value))),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function first(array $rows): mixed
    {
        $row = $rows[0] ?? [];

        return reset($row);
    }
}

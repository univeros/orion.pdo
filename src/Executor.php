<?php

declare(strict_types=1);

namespace Orion\Pdo;

use Throwable;

/**
 * Runs SQL on the host's connection for {@see SqlStorage}: a PDO handle ({@see PdoExecutor}) or an ORM's connection
 * (the Univeros module's Cycle executor). Parameters are positional and already plain: strings, ints, bools, null.
 * `transaction()` joins a transaction the host opened on the same connection rather than starting a second one.
 */
interface Executor
{
    public function dialect(): Dialect;

    /**
     * @param list<string|int|bool|null> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params): array;

    /**
     * @param list<string|int|bool|null> $params
     * @return int the rows the statement affected
     */
    public function execute(string $sql, array $params): int;

    /**
     * Runs `$fn` in a transaction on the connection (a savepoint when one is open), committing on return and rolling
     * back when it throws.
     *
     * @template R
     * @param callable(): R $fn
     * @return R
     */
    public function transaction(callable $fn): mixed;

    /**
     * Whether the failure is a repeated primary or unique key, the only integrity error that means someone else
     * inserted first.
     */
    public function isDuplicate(Throwable $failure): bool;
}

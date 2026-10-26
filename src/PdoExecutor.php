<?php

declare(strict_types=1);

namespace Orion\Pdo;

use InvalidArgumentException;
use Override;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function is_bool;
use function is_int;
use function str_contains;

/**
 * {@see Executor} on the host's PDO handle, never reconfigured: it refuses a connection not in `ERRMODE_EXCEPTION`.
 * `transaction()` opens a transaction only when the connection has none, and otherwise joins the host's as a
 * savepoint, so the outbox row commits or rolls back with the host's entity write.
 */
final class PdoExecutor implements Executor
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
    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    #[Override]
    public function select(string $sql, array $params): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    #[Override]
    public function execute(string $sql, array $params): int
    {
        return $this->run($sql, $params)->rowCount();
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
     * Unique violations only: PostgreSQL's 23505, MySQL's 1062, SQLite's UNIQUE and PRIMARY KEY failures; any other
     * integrity error (a NOT NULL, say) is not a race and stays what it is.
     */
    #[Override]
    public function isDuplicate(Throwable $failure): bool
    {
        return self::isUniqueViolation($failure);
    }

    /**
     * The same test for a failure an ORM wrapped: the PDO exception anywhere in its chain decides.
     */
    public static function isUniqueViolation(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null && !$cause instanceof PDOException; $cause = $cause->getPrevious()) {
        }
        if ($cause === null) {
            return false;
        }
        $info = $cause->errorInfo ?? [];

        return match ((string) ($info[0] ?? '')) {
            '23505' => true,
            '23000' => (int) ($info[1] ?? 0) === 1062 || str_contains((string) ($info[2] ?? ''), 'UNIQUE constraint failed'),
            default => false,
        };
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

    /**
     * @param list<string|int|bool|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();

        return $statement;
    }
}

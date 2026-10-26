<?php

declare(strict_types=1);

namespace Orion\Pdo;

use Orion\Storage\Storage;
use Override;
use PDO;

/**
 * {@see Storage} over the host's PDO connection: {@see SqlStorage} on a {@see PdoExecutor}. Identifiers are checked
 * and quoted, every value is bound, dates go in as UTC. `transaction()` joins a transaction the host already opened
 * (as a savepoint) instead of starting a second one, so the outbox row commits or rolls back with the host's entity
 * write.
 */
final class PdoStorage implements Storage
{
    private readonly SqlStorage $sql;

    public function __construct(PDO $pdo)
    {
        $this->sql = new SqlStorage(new PdoExecutor($pdo));
    }

    #[Override]
    public function findOne(string $table, array $criteria): ?array
    {
        return $this->sql->findOne($table, $criteria);
    }

    #[Override]
    public function findMany(string $table, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->sql->findMany($table, $criteria, $orderBy, $limit, $offset);
    }

    #[Override]
    public function insert(string $table, array $row): void
    {
        $this->sql->insert($table, $row);
    }

    #[Override]
    public function update(string $table, array $criteria, array $data): int
    {
        return $this->sql->update($table, $criteria, $data);
    }

    #[Override]
    public function delete(string $table, array $criteria): int
    {
        return $this->sql->delete($table, $criteria);
    }

    #[Override]
    public function count(string $table, array $criteria): int
    {
        return $this->sql->count($table, $criteria);
    }

    #[Override]
    public function increment(string $table, array $criteria, string $column): ?int
    {
        return $this->sql->increment($table, $criteria, $column);
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        return $this->sql->transaction($fn);
    }
}

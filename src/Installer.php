<?php

declare(strict_types=1);

namespace Orion\Pdo;

use LogicException;
use Orion\Storage\Schema;
use Orion\Storage\Table;
use PDO;

/**
 * What a host's migration or console command calls to install Orion's tables on its connection. A table that
 * already exists is left as it is, rows and columns alike, so installing twice is safe. On MySQL, DDL commits any
 * open transaction, so installing inside one is refused. `uninstall()` drops tables: for tests and teardown.
 */
final class Installer
{
    /**
     * @param list<Table>|null $tables Orion's own when null
     * @return list<string> the tables created, in order
     */
    public static function install(PDO $pdo, ?array $tables = null): array
    {
        $dialect = self::dialect($pdo);
        $created = [];
        foreach ($tables ?? Schema::tables() as $table) {
            if (self::exists($pdo, $dialect, $table->name)) {
                continue;
            }
            foreach (SqlSchema::create($table, $dialect) as $statement) {
                $pdo->exec($statement);
            }
            $created[] = $table->name;
        }

        return $created;
    }

    /**
     * @param list<Table>|null $tables Orion's own when null
     */
    public static function uninstall(PDO $pdo, ?array $tables = null): void
    {
        $dialect = self::dialect($pdo);
        foreach ($tables ?? Schema::tables() as $table) {
            $pdo->exec(SqlSchema::drop($table, $dialect));
        }
    }

    private static function dialect(PDO $pdo): Dialect
    {
        $dialect = Dialect::of($pdo);
        if ($dialect === Dialect::Mysql && $pdo->inTransaction()) {
            throw new LogicException('MySQL commits an open transaction on DDL; install or uninstall Orion\'s tables outside one.');
        }

        return $dialect;
    }

    private static function exists(PDO $pdo, Dialect $dialect, string $table): bool
    {
        $sql = match ($dialect) {
            Dialect::Sqlite => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            Dialect::Postgres => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            Dialect::Mysql => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        };
        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }
}

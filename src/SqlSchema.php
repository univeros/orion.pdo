<?php

declare(strict_types=1);

namespace Orion\Pdo;

use Orion\Storage\Column;
use Orion\Storage\ColumnType;
use Orion\Storage\Table;

use function array_map;
use function implode;
use function sprintf;

/**
 * DDL for tables-as-data, per dialect. Dates are stored as UTC with microseconds, JSON natively where the database
 * has a type for it.
 */
final class SqlSchema
{
    /**
     * @return list<string> the CREATE TABLE statement, then one CREATE INDEX per index
     */
    public static function create(Table $table, Dialect $dialect): array
    {
        $lines = array_map(
            static fn(Column $column): string => sprintf('    %s %s%s', $dialect->quote($column->name), self::type($column, $dialect), $column->nullable ? ' NULL' : ' NOT NULL'),
            $table->columns,
        );
        $primary = array_map(static fn(Column $column): string => $dialect->quote($column->name), $table->primaryKey());
        if ($primary !== []) {
            $lines[] = sprintf('    PRIMARY KEY (%s)', implode(', ', $primary));
        }

        $statements = [sprintf("CREATE TABLE %s (\n%s\n)", $dialect->quote($table->name), implode(",\n", $lines))];
        foreach ($table->indexes as $index) {
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index->unique ? 'UNIQUE ' : '',
                $dialect->quote($index->name),
                $dialect->quote($table->name),
                implode(', ', array_map($dialect->quote(...), $index->columns)),
            );
        }

        return $statements;
    }

    public static function drop(Table $table, Dialect $dialect): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', $dialect->quote($table->name));
    }

    private static function type(Column $column, Dialect $dialect): string
    {
        return match ($column->type) {
            ColumnType::String => sprintf('VARCHAR(%d)', $column->length ?? 255),
            ColumnType::Text => $dialect === Dialect::Mysql ? 'LONGTEXT' : 'TEXT',
            ColumnType::Int => $dialect === Dialect::Sqlite ? 'INTEGER' : 'BIGINT',
            ColumnType::DateTime => match ($dialect) {
                Dialect::Postgres => 'TIMESTAMP(6)',
                Dialect::Mysql => 'DATETIME(6)',
                Dialect::Sqlite => 'TEXT',
            },
            ColumnType::Json => match ($dialect) {
                Dialect::Postgres => 'JSONB',
                Dialect::Mysql => 'JSON',
                Dialect::Sqlite => 'TEXT',
            },
        };
    }
}

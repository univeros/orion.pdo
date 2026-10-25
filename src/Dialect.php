<?php

declare(strict_types=1);

namespace Orion\Pdo;

use InvalidArgumentException;
use PDO;

use function sprintf;

enum Dialect: string
{
    case Postgres = 'pgsql';
    case Mysql = 'mysql';
    case Sqlite = 'sqlite';

    public static function of(PDO $pdo): self
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return self::tryFrom($driver) ?? throw new InvalidArgumentException(sprintf('The PDO driver "%s" is not supported; use pgsql, mysql or sqlite.', $driver));
    }

    /**
     * Quotes an identifier already checked by {@see \Orion\Storage\Identifier}.
     */
    public function quote(string $identifier): string
    {
        return $this === self::Mysql ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }
}

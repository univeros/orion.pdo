<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use InvalidArgumentException;
use Orion\Pdo\Installer;
use Orion\Pdo\PdoStorage;
use Orion\Storage\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    public function testInstallCreatesOrionsTablesOnceAndIsIdempotent(): void
    {
        $pdo = new PDO('sqlite::memory:');

        self::assertSame(['orion_outbox', 'orion_index', 'orion_alias', 'orion_receipt'], Installer::install($pdo));
        self::assertSame([], Installer::install($pdo));
    }

    public function testInstallLeavesAnExistingTableAlone(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE "orion_alias" ("alias" TEXT)');

        self::assertSame(['orion_outbox', 'orion_index', 'orion_receipt'], Installer::install($pdo));
    }

    public function testTheOutboxOffsetIsUnique(): void
    {
        $pdo = new PDO('sqlite::memory:');
        Installer::install($pdo);
        $storage = new PdoStorage($pdo);
        $row = ['outbox_offset' => 1, 'index_name' => 'products', 'document_id' => '1', 'operation' => 'index', 'payload' => null, 'recorded_at' => new \DateTimeImmutable()];
        $storage->insert(Schema::OUTBOX, ['id' => 'a', ...$row]);

        $this->expectException(\PDOException::class);

        $storage->insert(Schema::OUTBOX, ['id' => 'b', ...$row]);
    }

    public function testTheHostsConnectionIsNotReconfigured(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(InvalidArgumentException::class);

        new PdoStorage($pdo);
    }
}

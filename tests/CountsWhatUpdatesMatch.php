<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use Orion\Pdo\PdoStorage;
use Orion\Storage\Schema;
use PDO;

/**
 * A compare-and-set (a lease taken by holder, a registration forgotten once) is only safe if `update()` counts the
 * rows it actually changed under the latest committed state, not under the transaction's snapshot: MySQL's repeatable
 * read would otherwise report a match the UPDATE never made, and two runs would both win.
 */
trait CountsWhatUpdatesMatch
{
    abstract protected function connect(): PDO;

    public function testACompareAndSetAfterAnotherCommitMatchesNothing(): void
    {
        $pdo = $this->connect();
        $storage = new PdoStorage($pdo);
        $storage->insert(Schema::COUNTER, ['name' => 'lease', 'value' => 1]);
        $other = new PdoStorage($this->connect());
        $pdo->beginTransaction();
        try {
            self::assertNotNull($storage->findOne(Schema::COUNTER, ['name' => 'lease']), 'The read that fixes the snapshot.');
            $other->update(Schema::COUNTER, ['name' => 'lease'], ['value' => 2]);

            self::assertSame(0, $storage->update(Schema::COUNTER, ['name' => 'lease', 'value' => 1], ['value' => 3]));
        } finally {
            $pdo->rollBack();
        }
        self::assertEquals(2, $storage->findOne(Schema::COUNTER, ['name' => 'lease'])['value'] ?? null);
    }
}

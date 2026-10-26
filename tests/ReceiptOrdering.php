<?php

declare(strict_types=1);

namespace Orion\Pdo\Tests;

use DateTimeImmutable;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Orion;
use Orion\Pdo\Installer;
use Orion\Pdo\PdoStorage;
use Orion\Receipt\ReceiptStore;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FaultyEngine;
use Orion\Testing\FixedClock;
use Orion\Testing\AliasingEngine;
use Orion\Wiring\Config;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

use function str_repeat;

/**
 * Receipts on two connections: another process issuing a receipt while an apply is mid-transaction must neither break
 * the chain nor fail the apply. Under MySQL's repeatable read this holds only if the receipt is taken before anything
 * else in the transaction reads.
 */
abstract class ReceiptOrdering extends TestCase
{
    abstract protected function connect(): PDO;

    abstract protected function limitLockWait(PDO $pdo): void;

    public function testAReceiptIssuedElsewhereMidApplyDoesNotBreakTheChain(): void
    {
        $first = $this->connect();
        Installer::uninstall($first);
        Installer::install($first);
        $second = $this->connect();
        $this->limitLockWait($second);
        $signer = SodiumSigner::fromSeed(str_repeat("\x06", 32), 'ordering');
        $clock = new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00'));
        $engine = new FaultyEngine(new AliasingEngine());
        $graph = Orion::create(new Config(
            storage: new PdoStorage($first),
            engines: new EngineRegistry(native: $engine),
            tenancy: Tenancy::single(),
            clock: $clock,
            receiptSigner: $signer,
        ))->graph();
        $elsewhere = new ReceiptStore(new PdoStorage($second), $signer, $clock);
        $orders = new IndexRegistration(IndexName::of('orders_v1'), IndexName::of('orders'), 'native', 1, IndexSchema::for('orders')->keyword('status'), $clock->now());
        // While the create's transaction swaps the alias, another connection issues a receipt. It either queues behind
        // the create's receipt lock (and gives up here) or commits first; the create must succeed either way.
        $engine->beforeNext(FaultyEngine::ALIAS, static function () use ($elsewhere, $orders): void {
            try {
                $elsewhere->append(Operation::Created, $orders, null, 0, 0, 0);
            } catch (Throwable) {
            }
        });

        $outcome = $graph->planner('native')->apply(IndexSchema::for('products')->keyword('sku'));

        self::assertSame(Operation::Created, $outcome->operation);
        self::assertNotNull($outcome->receipt);
        self::assertTrue($graph->verifier()->verify()->ok());
    }
}

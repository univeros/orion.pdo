# orion-search/pdo

Orion's `Storage` port over PDO for PostgreSQL, MySQL and SQLite, on the connection your application already
uses. That is the point: the outbox row Orion records commits or rolls back with your entity write, because it is
written on the same connection and joins the transaction your code (or your ORM) already opened.

```sh
composer require orion-search/pdo
```

```php
use Orion\Pdo\Installer;
use Orion\Pdo\PdoStorage;

Installer::install($pdo);          // creates the orion_* tables the connection lacks; idempotent
$storage = new PdoStorage($pdo);   // pass it to Orion\Wiring\Config
```

The connection must use `PDO::ERRMODE_EXCEPTION` (PHP's default since 8.0); `PdoStorage` never reconfigures it.
Proven by `Orion\Testing\StorageConformanceTestCase` on SQLite, PostgreSQL and MySQL.

How the outbox uses the connection: the [Indexing guide](../../docs/guides/indexing.md).

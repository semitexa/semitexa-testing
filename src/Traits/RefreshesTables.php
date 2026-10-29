<?php

declare(strict_types=1);

namespace Semitexa\Testing\Traits;

use PHPUnit\Framework\Attributes\Before;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\OrmManager;

/**
 * Empties tables before a test, for tests that cannot run inside a rolled-back
 * transaction (see RollsBackDatabase): the code under test commits on purpose,
 * runs coroutines, or changes the schema.
 *
 *     final class ImportCatalogueTest extends TestCase
 *     {
 *         use RefreshesTables;
 *
 *         protected function tablesToRefresh(): array
 *         {
 *             return [ProductResource::class, 'import_log'];
 *         }
 *     }
 *
 * Each entry is a table name on the default connection, or a resource model
 * class — its #[FromTable] table, on its #[Connection]. refreshTables() empties
 * tables on demand, in the middle of a test as well.
 *
 * On MySQL the tables are truncated (AUTO_INCREMENT starts again), with foreign
 * key checks off for the duration. When the test also rolls back its database,
 * the tables are emptied with DELETE instead: TRUNCATE would commit that
 * transaction. On SQLite rows are deleted and AUTOINCREMENT counters reset.
 *
 * Only point a test run at a database whose contents you can lose:
 * `bin/semitexa test:run` uses the test database, DB_TEST_DATABASE.
 */
trait RefreshesTables
{
    /**
     * Tables emptied before every test of the class: table names, or resource
     * model classes.
     *
     * @return list<string>
     */
    protected function tablesToRefresh(): array
    {
        return [];
    }

    #[Before]
    protected function refreshDeclaredTables(): void
    {
        $tables = $this->tablesToRefresh();
        if ($tables !== []) {
            $this->refreshTables(...$tables);
        }
    }

    /** Empty these tables now: table names (default connection) or resource model classes. */
    protected function refreshTables(string ...$tablesOrResources): void
    {
        /** @var array<string, list<string>> $byConnection */
        $byConnection = [];
        foreach ($tablesOrResources as $tableOrResource) {
            if (class_exists($tableOrResource)) {
                $metadata = (new ResourceModelMetadataRegistry())->for($tableOrResource);
                $byConnection[$metadata->connectionName][] = $metadata->tableName;
            } else {
                $byConnection['default'][] = $tableOrResource;
            }
        }

        foreach ($byConnection as $connectionName => $tables) {
            self::emptyTables($connectionName, array_values(array_unique($tables)));
        }
    }

    /** @param list<string> $tables */
    private static function emptyTables(string $connectionName, array $tables): void
    {
        $orm = new OrmManager(config: ConnectionConfig::fromEnvironment($connectionName), connectionName: $connectionName);

        try {
            $adapter = $orm->getAdapter();
            if ($adapter instanceof SqliteAdapter) {
                self::emptySqliteTables($adapter->getPdo(), $tables, SharedTestTransaction::for($connectionName, 'sqlite') !== null);

                return;
            }

            $transactional = SharedTestTransaction::for($connectionName, 'mysql') !== null;
            $pool = $orm->getPool();
            $pdo = $pool->pop();
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    foreach ($tables as $table) {
                        $pdo->exec(($transactional ? 'DELETE FROM ' : 'TRUNCATE TABLE ') . SqlIdentifier::quote($table));
                    }
                } finally {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                }
            } finally {
                $pool->push($pdo);
            }
        } finally {
            $orm->shutdown();
        }
    }

    /** @param list<string> $tables */
    private static function emptySqliteTables(\PDO $pdo, array $tables, bool $transactional): void
    {
        // foreign_keys cannot change inside a transaction; deferring the checks
        // to a commit that a rolled-back test never reaches does the same job.
        $pdo->exec($transactional ? 'PRAGMA defer_foreign_keys = ON' : 'PRAGMA foreign_keys = OFF');
        try {
            foreach ($tables as $table) {
                $pdo->exec('DELETE FROM ' . SqlIdentifier::quote($table));
            }

            $sequence = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");
            $hasSequence = false;
            if ($sequence !== false) {
                $hasSequence = $sequence->fetchColumn() !== false;
                $sequence->closeCursor();
            }
            if ($hasSequence) {
                $reset = $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ?');
                foreach ($tables as $table) {
                    $reset->execute([$table]);
                }
            }
        } finally {
            if (!$transactional) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }
}

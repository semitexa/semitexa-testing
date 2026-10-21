<?php

declare(strict_types=1);

namespace Semitexa\Testing\Traits;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;

/**
 * Each test runs inside a database transaction that is rolled back after it,
 * so the rows the test writes — and the rows the application code it calls
 * writes — are gone when the next test starts.
 *
 *     final class PlaceOrderTest extends TestCase
 *     {
 *         use RollsBackDatabase;
 *         use CallsRoutes;
 *
 *         #[Test]
 *         public function an_order_is_stored(): void
 *         {
 *             $this->postJson('/api/orders', ['sku' => 'A-1'])->assertStatus(201);
 *             // the order row exists here, and not after the test
 *         }
 *     }
 *
 * Every OrmManager of the process joins the transaction — the container's, the
 * ones repositories build with `new OrmManager()`, the ones an in-process route
 * call reaches — and a TransactionManager::run() inside the test becomes a
 * savepoint, so it never commits. Two consequences follow from that:
 *
 *   - Events that wait for a commit (resource-changed signals and anything else
 *     TransactionManager buffers) never fire. Assert on rows, not on them.
 *   - On MySQL, DDL (CREATE, ALTER, DROP) and TRUNCATE commit implicitly. The
 *     test then fails after it ends, saying so, instead of leaving its rows
 *     behind silently. Create schema in setUpBeforeClass(); empty tables with
 *     RefreshesTables.
 *
 * The transaction opens before setUp() and is rolled back after tearDown(), so
 * the rows setUp() writes are rolled back too. Tests that cannot run inside a
 * transaction — the code under test spawns coroutines, or enables Swoole hooks —
 * use RefreshesTables instead.
 */
trait RollsBackDatabase
{
    /** @var list<SharedTestTransaction> */
    private array $databaseTransactionsToRollBack = [];

    /**
     * The connection names whose writes are rolled back. A project with named
     * connections (#[Connection('analytics')]) adds them here.
     *
     * @return list<string>
     */
    protected function connectionsToRollBack(): array
    {
        return ['default'];
    }

    #[Before]
    protected function beginDatabaseTransaction(): void
    {
        foreach ($this->connectionsToRollBack() as $connectionName) {
            try {
                $this->databaseTransactionsToRollBack[] = SharedTestTransaction::begin($connectionName);
            } catch (\Throwable $e) {
                // Those already opened must not stay open into the next test.
                $this->rollBackDatabaseTransaction();

                throw $e;
            }
        }
    }

    #[After]
    protected function rollBackDatabaseTransaction(): void
    {
        $failures = [];
        foreach (array_reverse($this->databaseTransactionsToRollBack) as $transaction) {
            try {
                $transaction->rollBack();
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        $this->databaseTransactionsToRollBack = [];

        if ($failures !== []) {
            Assert::fail(implode("\n", $failures));
        }
    }
}

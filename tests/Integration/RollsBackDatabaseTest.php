<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\RollsBackDatabase;

/**
 * RollsBackDatabase against the MySQL test database `bin/semitexa test:run`
 * uses. The application code here gets its connection the way it does in a
 * project — `new OrmManager()`, a ConnectionRegistry manager — and is never
 * handed the test's connection; its rows must still be gone after the test.
 *
 * The trait's hooks are driven by hand (an object using the trait) so the test
 * can look at the database after the rollback; RollsBackDatabaseLifecycleTest
 * covers the PHPUnit wiring.
 */
final class RollsBackDatabaseTest extends TestCase
{
    public const TABLE = 'semitexa_testing_rollback_probe';
    private const DDL_TABLE = 'semitexa_testing_rollback_ddl';

    public static function setUpBeforeClass(): void
    {
        self::createProbeTable();
    }

    public static function tearDownAfterClass(): void
    {
        self::observer()->getAdapter()->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        self::observer()->getAdapter()->execute('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
    }

    protected function tearDown(): void
    {
        // A failed assertion before end() must not leave the transaction open.
        try {
            SharedTestTransaction::for('default', 'mysql')?->rollBack();
        } catch (\LogicException) {
        }
    }

    /** DDL commits implicitly on MySQL, so the schema is made outside every test transaction. */
    public static function createProbeTable(): void
    {
        $orm = new OrmManager();
        // No skip: a gate that cannot reach the database must not pass.
        self::assertSame('mysql', $orm->getDriver(), 'These tests prove the MySQL path; bin/semitexa test:run runs them against the MySQL test database.');

        $orm->getAdapter()->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE
            . ' (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(32) NOT NULL) ENGINE=InnoDB',
        );
        $orm->getAdapter()->execute('DELETE FROM ' . self::TABLE);
        $orm->shutdown();
    }

    /**
     * A connection that is NOT the test's: another connection name, same
     * database. It sees committed rows only.
     */
    public static function observer(): OrmManager
    {
        return new OrmManager(config: ConnectionConfig::fromEnvironment('default'), connectionName: 'rollback_observer');
    }

    public static function committedRows(): int
    {
        return (int) self::observer()->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows[0]['c'];
    }

    #[Test]
    public function rows_written_by_application_managers_are_gone_after_the_test(): void
    {
        $test = self::harness();
        $test->begin();

        // The fallback a repository takes: a bare manager of its own.
        (new OrmManager())->getAdapter()->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('raw')");
        // The container's manager comes from a ConnectionRegistry; its
        // transaction thinks it commits.
        (new ConnectionRegistry())->manager()->getTransactionManager()->run(
            static fn ($adapter) => $adapter->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('committed')"),
        );
        // An in-process request wipes the CLI CoroutineLocal store in between.
        CoroutineLocal::endRequest();
        (new OrmManager())->getQueryAdapter()->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('after request')");

        $inside = (int) (new OrmManager())->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows[0]['c'];
        self::assertSame(3, $inside, 'the test and the code it calls see the rows');
        self::assertSame(0, self::committedRows(), 'nothing is committed while the test runs');

        $test->end();

        self::assertSame(0, self::committedRows(), 'and nothing is left after it');
        self::assertSame(
            0,
            (int) (new OrmManager())->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows[0]['c'],
        );
    }

    #[Test]
    public function ddl_inside_the_test_fails_loudly_instead_of_leaving_rows(): void
    {
        $test = self::harness();
        $test->begin();
        (new OrmManager())->getAdapter()->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('before ddl')");
        (new OrmManager())->getAdapter()->execute('CREATE TABLE IF NOT EXISTS ' . self::DDL_TABLE . ' (id INT)');

        $this->assertTheRollbackFails($test);
    }

    #[Test]
    public function truncate_inside_the_test_fails_loudly_instead_of_leaving_rows(): void
    {
        $test = self::harness();
        $test->begin();
        (new OrmManager())->getAdapter()->execute('TRUNCATE TABLE ' . self::TABLE);
        (new OrmManager())->getAdapter()->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('after truncate')");

        $this->assertTheRollbackFails($test);
    }

    private function assertTheRollbackFails(object $test): void
    {
        try {
            $test->end();
            self::fail('A test whose transaction was committed must fail.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('was committed before the test ended', $e->getMessage());
        } finally {
            $leaked = self::committedRows();
            self::observer()->getAdapter()->execute('DELETE FROM ' . self::TABLE);
        }

        self::assertSame(1, $leaked, 'the row did leak — exactly what the failure reports');
    }

    private static function harness(): object
    {
        return new class () {
            use RollsBackDatabase;

            public function begin(): void
            {
                $this->beginDatabaseTransaction();
            }

            public function end(): void
            {
                $this->rollBackDatabaseTransaction();
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\RefreshesTables;
use Semitexa\Testing\Traits\RollsBackDatabase;

/**
 * RefreshesTables against the MySQL test database: committed rows are emptied
 * by table name and by resource class, AUTO_INCREMENT starts again, and inside
 * a rolled-back test the tables are emptied without committing it.
 */
final class RefreshesTablesTest extends TestCase
{
    use RefreshesTables;

    public const TABLE = 'semitexa_testing_refresh_probe';
    private const CHILD = 'semitexa_testing_refresh_child';

    public static function setUpBeforeClass(): void
    {
        $orm = new OrmManager();
        // No skip: a gate that cannot reach the database must not pass.
        self::assertSame('mysql', $orm->getDriver(), 'These tests prove the MySQL path; bin/semitexa test:run runs them against the MySQL test database.');

        $adapter = $orm->getAdapter();
        $adapter->execute('DROP TABLE IF EXISTS ' . self::CHILD);
        $adapter->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $adapter->execute('CREATE TABLE ' . self::TABLE . ' (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(32) NOT NULL) ENGINE=InnoDB');
        // A referencing table: TRUNCATE of the parent is refused while
        // foreign key checks are on, even when the child is empty.
        $adapter->execute(
            'CREATE TABLE ' . self::CHILD . ' (id INT AUTO_INCREMENT PRIMARY KEY, probe_id INT NOT NULL,'
            . ' FOREIGN KEY (probe_id) REFERENCES ' . self::TABLE . ' (id)) ENGINE=InnoDB',
        );
        $orm->shutdown();
    }

    public static function tearDownAfterClass(): void
    {
        $adapter = (new OrmManager())->getAdapter();
        $adapter->execute('DROP TABLE IF EXISTS ' . self::CHILD);
        $adapter->execute('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function setUp(): void
    {
        $adapter = (new OrmManager())->getAdapter();
        $adapter->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('a'), ('b')");
        $adapter->execute('INSERT INTO ' . self::CHILD . ' (probe_id) SELECT MIN(id) FROM ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        // A failed assertion above must not leave the transaction open.
        try {
            SharedTestTransaction::for('default', 'mysql')?->rollBack();
        } catch (\LogicException) {
        }
        $this->refreshTables(self::CHILD, self::TABLE);
    }

    #[Test]
    public function tables_named_or_given_by_resource_are_emptied_and_counters_reset(): void
    {
        $this->refreshTables(self::CHILD, RefreshProbeResource::class);

        self::assertSame(0, self::rows(self::TABLE));
        self::assertSame(0, self::rows(self::CHILD));

        $adapter = (new OrmManager())->getAdapter();
        $adapter->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('c')");
        self::assertSame(1, (int) $adapter->execute('SELECT MAX(id) AS m FROM ' . self::TABLE)->rows[0]['m'], 'AUTO_INCREMENT starts again');
    }

    #[Test]
    public function inside_a_rolled_back_test_tables_are_emptied_without_committing_it(): void
    {
        $test = new class () {
            use RollsBackDatabase;
            use RefreshesTables;

            public function begin(): void
            {
                $this->beginDatabaseTransaction();
            }

            public function refresh(string ...$tables): void
            {
                $this->refreshTables(...$tables);
            }

            public function end(): void
            {
                $this->rollBackDatabaseTransaction();
            }
        };

        $test->begin();
        $test->refresh(self::CHILD, self::TABLE);
        self::assertSame(
            0,
            (int) (new OrmManager())->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows[0]['c'],
            'emptied for the test',
        );
        self::assertSame(2, self::rows(self::TABLE), 'but not committed');
        // Would fail with "was committed" had the refresh truncated.
        $test->end();

        self::assertSame(2, self::rows(self::TABLE), 'and back after it, since the emptying was rolled back too');
        self::assertSame(1, self::rows(self::CHILD));
    }

    private static function rows(string $table): int
    {
        $observer = new OrmManager(config: ConnectionConfig::fromEnvironment('default'), connectionName: 'refresh_observer');

        return (int) $observer->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . $table)->rows[0]['c'];
    }
}

#[FromTable(name: RefreshesTablesTest::TABLE)]
final readonly class RefreshProbeResource
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Int)]
        public int $id,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $v,
    ) {
    }
}

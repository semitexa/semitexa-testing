<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Testing\Traits\RollsBackDatabase;

/**
 * The trait as a project uses it: PHPUnit opens the transaction before setUp()
 * and rolls it back after the test. The first test writes in setUp() and in its
 * body; the second, which runs after it, finds nothing — on its own connection
 * and on one that is not the test's.
 */
final class RollsBackDatabaseLifecycleTest extends TestCase
{
    use RollsBackDatabase;

    private const TABLE = 'semitexa_testing_rollback_lifecycle';

    public static function setUpBeforeClass(): void
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

    public static function tearDownAfterClass(): void
    {
        (new OrmManager())->getAdapter()->execute('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function setUp(): void
    {
        (new OrmManager())->getAdapter()->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('from setUp')");
    }

    #[Test]
    public function a_test_writes_rows(): void
    {
        (new OrmManager())->getTransactionManager()->run(
            static fn ($adapter) => $adapter->execute('INSERT INTO ' . self::TABLE . " (v) VALUES ('from the test')"),
        );

        self::assertSame(2, self::rows(new OrmManager()));
    }

    #[Test]
    public function the_next_test_finds_none_of_them(): void
    {
        // setUp() has written its own row again, inside this test's transaction.
        self::assertSame(1, self::rows(new OrmManager()));
        self::assertSame(
            0,
            self::rows(new OrmManager(config: ConnectionConfig::fromEnvironment('default'), connectionName: 'rollback_observer')),
            'nothing any test wrote was committed',
        );
    }

    private static function rows(OrmManager $orm): int
    {
        return (int) $orm->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows[0]['c'];
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Traits;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Testing\Traits\RollsBackDatabase;

/**
 * A begin that fails, followed by a cleanup rollback that fails too: the
 * report showed the cleanup, and the begin failure that caused it was lost.
 */
final class RollsBackDatabaseBeginFailureTest extends TestCase
{
    private const CONNECTION = 'rolls_back_begin_failure_probe';

    #[Test]
    public function the_begin_failure_is_reported_even_when_the_cleanup_fails(): void
    {
        $path = sys_get_temp_dir() . '/semitexa-begin-failure-' . bin2hex(random_bytes(6)) . '.sqlite';
        // Already open on the connection: the trait's own begin is refused.
        $held = SharedTestTransaction::begin(self::CONNECTION, new ConnectionConfig(driver: 'sqlite', sqlitePath: $path));
        $subject = new class (self::CONNECTION) {
            use RollsBackDatabase;

            public function __construct(private readonly string $connection) {}

            /** @return list<string> */
            protected function connectionsToRollBack(): array
            {
                return [$this->connection];
            }

            protected function rollBackDatabaseTransaction(): void
            {
                throw new AssertionFailedError('the cleanup rollback failed');
            }

            public function begin(): void
            {
                $this->beginDatabaseTransaction();
            }
        };

        try {
            $subject->begin();
            self::fail('a refused begin must not pass');
        } catch (\LogicException $e) {
            self::assertSame(
                'A test transaction is already open on connection "' . self::CONNECTION . '". Roll it back before opening another.',
                $e->getMessage(),
            );
        } finally {
            $held->rollBack();
            @unlink($path);
        }
    }
}

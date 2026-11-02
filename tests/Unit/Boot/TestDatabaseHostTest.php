<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Boot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Testing\Boot\TestDatabaseHost;

/** A host phpunit run stops at once with the command that works (bench-host-phpunit-db). */
final class TestDatabaseHostTest extends TestCase
{
    #[Test]
    public function a_mysql_host_that_does_not_resolve_stops_the_run_with_the_command_that_works(): void
    {
        $why = TestDatabaseHost::unresolvable('mysql', 'mysql', static fn (string $name): bool => false);

        self::assertIsString($why);
        self::assertStringContainsString("DB_HOST 'mysql' does not resolve", $why);
        self::assertStringContainsString('bin/semitexa test:run', $why);
    }

    #[Test]
    public function a_host_that_resolves_an_ip_or_no_mysql_at_all_runs(): void
    {
        $never = static function (string $name): bool {
            throw new \LogicException("{$name} must not be looked up");
        };

        self::assertNull(TestDatabaseHost::unresolvable('mysql', 'mysql', static fn (string $name): bool => true), 'resolves: the tests try');
        self::assertNull(TestDatabaseHost::unresolvable('mysql', '127.0.0.1', $never), 'an address needs no lookup');
        self::assertNull(TestDatabaseHost::unresolvable('sqlite', 'mysql', $never), 'SQLite has no host');
        self::assertNull(TestDatabaseHost::unresolvable('mysql', '', $never), 'no host configured is the tests\' to report');
    }
}

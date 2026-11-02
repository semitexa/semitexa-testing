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

    /** No DB_DRIVER is MySQL, as the ORM reads it. */
    #[Test]
    public function an_unset_driver_is_mysql(): void
    {
        $driver = getenv('DB_DRIVER');
        putenv('DB_DRIVER');
        try {
            self::assertIsString(TestDatabaseHost::unresolvable(null, 'mysql', static fn (string $name): bool => false));
        } finally {
            putenv($driver === false ? 'DB_DRIVER' : 'DB_DRIVER=' . $driver);
        }
    }

    #[Test]
    public function an_ipv6_address_needs_no_lookup(): void
    {
        $never = static function (string $name): bool {
            throw new \LogicException("{$name} must not be looked up");
        };

        self::assertNull(TestDatabaseHost::unresolvable('mysql', '::1', $never));
        self::assertNull(TestDatabaseHost::unresolvable('mysql', 'localhost', null), 'the real resolver finds localhost');
    }

    /** A name with an AAAA record and no A record resolves: gethostbyname() alone said it did not. */
    #[Test]
    public function a_host_with_only_an_ipv6_address_resolves(): void
    {
        $noIpv4 = static fn (string $name): string => $name;

        self::assertTrue(TestDatabaseHost::resolves('db6', $noIpv4, static fn (string $name): array => [['type' => 'AAAA', 'ipv6' => '2001:db8::1']]));
        self::assertFalse(TestDatabaseHost::resolves('nowhere', $noIpv4, static fn (string $name): array => []));
        self::assertFalse(TestDatabaseHost::resolves('nowhere', $noIpv4, static fn (string $name): bool => false), 'a failed lookup is no address');
        self::assertTrue(TestDatabaseHost::resolves('db4', static fn (string $name): string => '10.0.0.5', static function (string $name): never {
            throw new \LogicException('an IPv4 answer needs no AAAA lookup');
        }));
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

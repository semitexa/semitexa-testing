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

    /** The host checked is the one the ORM connects to: outside Docker a DB_CLI_HOST wins. */
    #[Test]
    public function the_host_is_the_one_the_orm_picks(): void
    {
        $env = static fn (array $vars): \Closure => static fn (string $key): ?string => $vars[$key] ?? null;
        $both = $env(['DB_HOST' => 'mysql', 'DB_CLI_HOST' => '127.0.0.1']);

        self::assertSame(['DB_CLI_HOST', '127.0.0.1'], TestDatabaseHost::ormHost(false, $both), 'on the host the CLI host is used');
        self::assertSame(['DB_HOST', 'mysql'], TestDatabaseHost::ormHost(true, $both), 'inside Docker the service name is');
        self::assertSame(['DB_HOST', 'mysql'], TestDatabaseHost::ormHost(false, $env(['DB_HOST' => 'mysql', 'DB_CLI_HOST' => ''])), 'an empty CLI host: DB_HOST');
        self::assertSame(['DB_HOST', '127.0.0.1'], TestDatabaseHost::ormHost(false, $env([])), "the ORM's default");
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

        // An /etc/hosts entry with only an IPv6 address: neither gethostbyname
        // nor a DNS record lookup sees it, the system resolver does.
        self::assertTrue(TestDatabaseHost::resolves('db6', $noIpv4, static fn (string $name): array => [], static fn (string $name): bool => true));

        self::assertTrue(TestDatabaseHost::resolves('db6', $noIpv4, static fn (string $name): array => [['type' => 'AAAA', 'ipv6' => '2001:db8::1']], static fn (string $name): bool => false));
        $noSystem = static fn (string $name): bool => false;
        self::assertFalse(TestDatabaseHost::resolves('nowhere', $noIpv4, static fn (string $name): array => [], $noSystem));
        self::assertFalse(TestDatabaseHost::resolves('nowhere', $noIpv4, static fn (string $name): bool => false, $noSystem), 'a failed lookup is no address');
        self::assertTrue(TestDatabaseHost::resolves('db4', static fn (string $name): string => '10.0.0.5', static function (string $name): never {
            throw new \LogicException('an IPv4 answer needs no AAAA lookup');
        }, static fn (string $name): bool => false));
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

<?php

declare(strict_types=1);

namespace Semitexa\Testing\Boot;

use Semitexa\Core\Environment;

/**
 * Whether the test process can reach the database it is configured for.
 *
 * A project's tests run inside its stack (`bin/semitexa test:run`), where
 * DB_HOST names the compose service `mysql`. `vendor/bin/phpunit` on the host
 * cannot resolve that name. The run used to go on and fail 9 to 16 tests with
 * DNS errors that read like broken code (bench-host-phpunit-db); the
 * bootstrap now stops with the command that works. Only a name that does not
 * resolve at all stops it: a database that resolves but is down is the
 * tests' to report, and a SQLite project has no host to resolve.
 */
final class TestDatabaseHost
{
    /**
     * Why the tests cannot reach their database, or null when they can try.
     *
     * @param (\Closure(string): bool)|null $resolves name => whether it resolves (DNS, /etc/hosts)
     */
    public static function unresolvable(?string $driver = null, ?string $host = null, ?\Closure $resolves = null): ?string
    {
        // The ORM's own default: no DB_DRIVER is MySQL.
        $driver ??= (string) Environment::getEnvValue('DB_DRIVER', 'mysql');
        if (!in_array(strtolower($driver), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $variable = 'DB_HOST';
        if ($host === null) {
            [$variable, $host] = self::ormHost();
        }
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $resolves ??= self::resolves(...);
        if ($resolves($host)) {
            return null;
        }

        return "{$variable} '{$host}' does not resolve from this process, so every database test would fail on DNS. "
            . "The tests run inside the project's stack: bin/semitexa test:run (add a path or --filter to narrow it).";
    }

    /**
     * The host the ORM connects to, as OrmManager::resolveDbHost() picks it:
     * outside Docker a set DB_CLI_HOST wins, else DB_HOST (default 127.0.0.1).
     *
     * @param (\Closure(string): ?string)|null $env a variable's value, Environment::getEnvValue() by default
     * @return array{string, string} the variable it came from, and the host
     */
    public static function ormHost(?bool $inDocker = null, ?\Closure $env = null): array
    {
        $inDocker ??= file_exists('/.dockerenv');
        $env ??= static fn (string $key): ?string => Environment::getEnvValue($key);
        if (!$inDocker) {
            $cli = $env('DB_CLI_HOST');
            if ($cli !== null && $cli !== '') {
                return ['DB_CLI_HOST', $cli];
            }
        }
        $host = $env('DB_HOST');

        return ['DB_HOST', $host ?? '127.0.0.1'];
    }

    /**
     * The system resolver first (getaddrinfo, both families, /etc/hosts
     * included) where ext-sockets offers it; then an IPv4 address (DNS or
     * /etc/hosts, through gethostbyname) or an AAAA record. A host reachable
     * over IPv6 only resolves too.
     *
     * @param (\Closure(string): string)|null $ipv4 gethostbyname() by default
     * @param (\Closure(string): mixed)|null $ipv6 the AAAA records, dns_get_record() by default
     * @param (\Closure(string): bool)|null $system getaddrinfo by default, when ext-sockets is loaded
     */
    public static function resolves(string $name, ?\Closure $ipv4 = null, ?\Closure $ipv6 = null, ?\Closure $system = null): bool
    {
        $system ??= function_exists('socket_addrinfo_lookup')
            ? static fn (string $n): bool => is_array($found = @socket_addrinfo_lookup($n)) && $found !== []
            : static fn (string $n): bool => false;
        if ($system($name)) {
            return true;
        }
        $ipv4 ??= static fn (string $n): string => gethostbyname($n);
        if ($ipv4($name) !== $name) {
            return true;
        }
        $ipv6 ??= static fn (string $n): mixed => @dns_get_record($n, DNS_AAAA);
        $aaaa = $ipv6($name);

        return is_array($aaaa) && $aaaa !== [];
    }
}

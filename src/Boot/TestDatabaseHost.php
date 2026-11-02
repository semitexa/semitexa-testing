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
        $driver ??= (string) Environment::getEnvValue('DB_DRIVER', '');
        if (!in_array(strtolower($driver), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $host ??= (string) Environment::getEnvValue('DB_HOST', '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $resolves ??= static fn (string $name): bool => gethostbyname($name) !== $name;
        if ($resolves($host)) {
            return null;
        }

        return "DB_HOST '{$host}' does not resolve from this process, so every database test would fail on DNS. "
            . "The tests run inside the project's stack: bin/semitexa test:run (add a path or --filter to narrow it).";
    }
}

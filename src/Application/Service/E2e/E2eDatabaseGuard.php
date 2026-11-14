<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Service\E2e;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

/**
 * Says why fixtures must not run on this database, or nothing when they may
 * (Fable's design, tk-e2e-specs-own-their-data). A fixture writes a sign-in
 * account with a password the runner knows, so all three must hold:
 *
 *  1. APP_ENV is one of ALLOWED_ENVS: an allowlist, so "staging" or "live" never pass;
 *  2. the connection is on the E2E database, DB_E2E_DATABASE (default semitexa_e2e);
 *  3. that database holds MARKER_TABLE with a row for this run's token, which the E2E
 *     runner writes as root right after it makes the database again and hands the app
 *     as SEMITEXA_E2E_TOKEN. A table alone is not enough: a dump of the E2E database
 *     restored elsewhere carries it, and anyone who may CREATE can make one (Fable's
 *     review, testing#40); the token is this run's and lives nowhere else.
 *
 * The third is the one that matters: a production database never holds this run's
 * token, whatever its name or the environment says. The E2E database is MySQL (the
 * runner makes it on the bundled one), so DATABASE() is asked as MySQL writes it.
 */
final class E2eDatabaseGuard
{
    public const MARKER_TABLE = '_semitexa_e2e';

    public const DEFAULT_DATABASE = 'semitexa_e2e';

    /** @var list<string> */
    public const ALLOWED_ENVS = ['dev', 'test', 'testing', 'e2e', 'local'];

    /** @param array<string, string> $env */
    public static function refusal(DatabaseAdapterInterface $db, array $env): ?string
    {
        $appEnv = strtolower(trim($env['APP_ENV'] ?? ''));
        if (!in_array($appEnv, self::ALLOWED_ENVS, true)) {
            return sprintf(
                "APP_ENV is '%s': E2E fixtures run only where it is %s, never in production or anything like it.",
                $env['APP_ENV'] ?? '(unset)',
                implode(', ', self::ALLOWED_ENVS),
            );
        }

        $expected = trim($env['DB_E2E_DATABASE'] ?? '');
        $expected = $expected !== '' ? $expected : self::DEFAULT_DATABASE;
        $current = $db->query('SELECT DATABASE() AS name')->rows[0]['name'] ?? null;
        if (!is_string($current) || $current !== $expected) {
            return sprintf(
                "The connection is on '%s', not the E2E database '%s' (DB_E2E_DATABASE): fixtures run only where the E2E runner points the app.",
                is_string($current) ? $current : '(none)',
                $expected,
            );
        }

        $marker = $db->execute(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :marker',
            ['marker' => self::MARKER_TABLE],
        )->rows;
        if ($marker === []) {
            return sprintf(
                "'%s' holds no %s table: only a database the E2E runner made has one, so this is not it.",
                $current,
                self::MARKER_TABLE,
            );
        }

        $token = trim($env['SEMITEXA_E2E_TOKEN'] ?? '');
        if ($token === '') {
            return 'SEMITEXA_E2E_TOKEN is not set: the E2E runner hands the app the token it wrote for this run.';
        }
        $run = $db->execute('SELECT 1 AS found FROM ' . self::MARKER_TABLE . ' WHERE token = :token', ['token' => $token])->rows;
        if ($run === []) {
            return sprintf("'%s' holds no row for this run's token: it was made by another run, or copied from one.", $current);
        }

        return null;
    }
}

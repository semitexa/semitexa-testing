<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\E2e;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Testing\Application\Service\E2e\E2eDatabaseGuard;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureDiscovery;
use Semitexa\Testing\Application\Service\E2e\Fixture\SignInOwnerFixture;
use Semitexa\Testing\Tests\Fixture\E2e\DuplicateIdFixture;
use Semitexa\Testing\Tests\Fixture\E2e\FirstFixture;
use Semitexa\Testing\Tests\Fixture\E2e\NotAFixture;
use Semitexa\Testing\Tests\Fixture\E2e\SamePriorityByIdFixture;

/**
 * E2E fixtures write a sign-in account with a password the runner knows, so they
 * run only on the database the E2E runner made (tk-e2e-specs-own-their-data).
 */
final class E2eFixturesTest extends TestCase
{
    private const E2E_ENV = ['APP_ENV' => 'dev', 'DB_E2E_DATABASE' => 'semitexa_e2e', 'SEMITEXA_E2E_TOKEN' => 'run-token'];

    #[Test]
    public function the_e2e_database_with_its_marker_is_the_only_one_accepted(): void
    {
        self::assertNull(E2eDatabaseGuard::refusal(self::database('semitexa_e2e', marker: true), self::E2E_ENV));
    }

    #[Test]
    public function production_is_refused_even_on_a_marked_e2e_database(): void
    {
        $marked = self::database('semitexa_e2e', marker: true);
        // An allowlist: "staging" or "live" is no E2E environment either (Fable's review, testing#40).
        foreach ([['APP_ENV' => 'prod'], ['APP_ENV' => 'production'], ['APP_ENV' => 'staging'], ['APP_ENV' => 'live'], ['APP_ENV' => ''], []] as $env) {
            self::assertStringContainsString('never in production', (string) E2eDatabaseGuard::refusal($marked, $env + ['DB_E2E_DATABASE' => 'semitexa_e2e', 'SEMITEXA_E2E_TOKEN' => 'run-token']), json_encode($env) ?: '');
        }
    }

    #[Test]
    public function another_database_is_refused_whatever_it_holds(): void
    {
        $refusal = E2eDatabaseGuard::refusal(self::database('semitexa', marker: true), self::E2E_ENV);
        self::assertStringContainsString("on 'semitexa', not the E2E database 'semitexa_e2e'", (string) $refusal);
    }

    #[Test]
    public function the_e2e_name_without_the_runners_marker_is_refused(): void
    {
        $refusal = E2eDatabaseGuard::refusal(self::database('semitexa_e2e', marker: false), self::E2E_ENV);
        self::assertStringContainsString('holds no ' . E2eDatabaseGuard::MARKER_TABLE . ' table', (string) $refusal);
    }

    #[Test]
    public function the_configured_e2e_name_is_the_one_asked_for(): void
    {
        $env = ['APP_ENV' => 'dev', 'DB_E2E_DATABASE' => 'shop_e2e', 'SEMITEXA_E2E_TOKEN' => 'run-token'];
        self::assertNull(E2eDatabaseGuard::refusal(self::database('shop_e2e', marker: true), $env));
        self::assertNotNull(E2eDatabaseGuard::refusal(self::database('semitexa_e2e', marker: true), $env));
    }

    /** Fable's review, testing#40: a dump of the E2E database restored elsewhere carries the table. */
    #[Test]
    public function the_marker_must_hold_this_runs_token(): void
    {
        $marked = self::database('semitexa_e2e', marker: true);
        self::assertStringContainsString('SEMITEXA_E2E_TOKEN is not set', (string) E2eDatabaseGuard::refusal($marked, ['SEMITEXA_E2E_TOKEN' => ' '] + self::E2E_ENV));
        self::assertStringContainsString("no row for this run's token", (string) E2eDatabaseGuard::refusal($marked, ['SEMITEXA_E2E_TOKEN' => 'another-run'] + self::E2E_ENV));
    }

    #[Test]
    public function fixtures_run_by_priority_then_id(): void
    {
        $order = array_column(E2eFixtureDiscovery::ordered([SignInOwnerFixture::class, SamePriorityByIdFixture::class, FirstFixture::class]), 'id');
        self::assertSame(['z.first', 'a.by-id', 'testing.sign-in-owner'], $order);
    }

    #[Test]
    public function an_id_two_classes_declare_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("id 'a.by-id' is declared by both");
        E2eFixtureDiscovery::ordered([SamePriorityByIdFixture::class, DuplicateIdFixture::class]);
    }

    #[Test]
    public function a_marked_class_that_is_no_fixture_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement');
        E2eFixtureDiscovery::ordered([NotAFixture::class]);
    }

    /** No password from the runner, no account: a fixed one is never written. */
    #[Test]
    public function the_owner_fixture_writes_no_account_without_the_runners_password(): void
    {
        $container = new class () implements ContainerInterface {
            public function get(string $id): never
            {
                throw new \LogicException('the container was asked before the password was');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEMITEXA_E2E_PASSWORD is not set');
        (new SignInOwnerFixture())->apply(new E2eFixtureContext(self::database('semitexa_e2e', marker: true), $container, ['SEMITEXA_E2E_PASSWORD' => '']));
    }

    private static function database(string $name, bool $marker): DatabaseAdapterInterface
    {
        return new class ($name, $marker) implements DatabaseAdapterInterface {
            public function __construct(private readonly string $name, private readonly bool $marker)
            {
            }

            public function supports(ServerCapability $capability): bool
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.0';
            }

            public function execute(string $sql, array $params = []): QueryResult
            {
                if (str_contains($sql, 'information_schema.tables') && ($params['marker'] ?? null) === E2eDatabaseGuard::MARKER_TABLE) {
                    return new QueryResult($this->marker ? [['found' => 1]] : []);
                }
                if ($sql === 'SELECT 1 AS found FROM ' . E2eDatabaseGuard::MARKER_TABLE . ' WHERE token = :token') {
                    return new QueryResult($this->marker && ($params['token'] ?? null) === 'run-token' ? [['found' => 1]] : []);
                }

                throw new \LogicException('unexpected statement: ' . $sql);
            }

            public function query(string $sql): QueryResult
            {
                if ($sql !== 'SELECT DATABASE() AS name') {
                    throw new \LogicException('unexpected statement: ' . $sql);
                }

                return new QueryResult([['name' => $this->name]]);
            }

            public function lastInsertId(): string
            {
                return '0';
            }
        };
    }
}

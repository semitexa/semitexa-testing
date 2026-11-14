<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Service\E2e;

use Psr\Container\ContainerInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

/**
 * What a fixture works with: the E2E database (already checked by
 * E2eDatabaseGuard), the container for the services that write it, and the
 * environment the runner set for this run.
 */
final readonly class E2eFixtureContext
{
    /** @param array<string, string> $env */
    public function __construct(
        public DatabaseAdapterInterface $db,
        public ContainerInterface $container,
        private array $env,
    ) {
    }

    /** The value the runner set, or null when it set none or an empty one. */
    public function env(string $key): ?string
    {
        $value = $this->env[$key] ?? '';

        return $value !== '' ? $value : null;
    }
}

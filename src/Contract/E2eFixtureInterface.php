<?php

declare(strict_types=1);

namespace Semitexa\Testing\Contract;

use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;

/**
 * Puts what E2E specs need into the E2E database (see AsE2eFixture). Runs on a
 * fresh database and on a copy of a developer's, so it creates or resets its
 * rows, never assumes either.
 */
interface E2eFixtureInterface
{
    /** What it set up, in a line for the runner's log. */
    public function apply(E2eFixtureContext $context): string;
}

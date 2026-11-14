<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Fixture\E2e;

use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Attributes\AsE2eFixture;
use Semitexa\Testing\Contract\E2eFixtureInterface;

#[AsE2eFixture(id: 'a.by-id')]
final class DuplicateIdFixture implements E2eFixtureInterface
{
    public function apply(E2eFixtureContext $context): string
    {
        return 'duplicate';
    }
}

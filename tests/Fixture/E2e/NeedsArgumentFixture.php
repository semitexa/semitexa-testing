<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Fixture\E2e;

use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Attributes\AsE2eFixture;
use Semitexa\Testing\Contract\E2eFixtureInterface;

#[AsE2eFixture(id: 'needs.argument')]
final class NeedsArgumentFixture implements E2eFixtureInterface
{
    public function __construct(private readonly string $label)
    {
    }

    public function apply(E2eFixtureContext $context): string
    {
        return $this->label;
    }
}

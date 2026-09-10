<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

interface BuilderFixtureClockInterface
{
    public function now(): string;
}

#[AsService]
final class BuilderFixtureService
{
    protected BuilderFixtureClockInterface $clock;

    public function stamp(): string
    {
        return 'at ' . $this->clock->now();
    }
}

#[AsService]
final class BuilderFixtureInitialising implements InitializesAfterInjectionInterface
{
    public bool $initialized = false;

    public function initialize(): void
    {
        $this->initialized = true;
    }
}

/** Deliberately not container-managed: a value object with a real constructor. */
final readonly class BuilderFixtureValueObject
{
    public function __construct(public string $name)
    {
    }
}

final class BuildsContainerManagedObjectsTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function it_builds_a_service_with_a_substituted_collaborator(): void
    {
        $service = $this->createWithDependencies(BuilderFixtureService::class, [
            'clock' => new class implements BuilderFixtureClockInterface {
                public function now(): string
                {
                    return 'noon';
                }
            },
        ]);

        self::assertSame('at noon', $service->stamp());
    }

    #[Test]
    public function it_runs_initialize_because_the_container_does(): void
    {
        $service = $this->createWithDependencies(BuilderFixtureInitialising::class);

        self::assertTrue(
            $service->initialized,
            'a test that skips initialize() exercises a state the runtime never produces',
        );
    }

    #[Test]
    public function it_refuses_a_value_object(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/is not container-managed.*It has a constructor/s');

        $this->createWithDependencies(BuilderFixtureValueObject::class, ['name' => 'x']);
    }

    #[Test]
    public function it_refuses_a_substitute_for_a_property_that_does_not_exist(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/has no property \$nope/');

        $this->createWithDependencies(BuilderFixtureService::class, ['nope' => 1]);
    }
}

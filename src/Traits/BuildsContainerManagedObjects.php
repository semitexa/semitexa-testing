<?php

declare(strict_types=1);

namespace Semitexa\Testing\Traits;

use ReflectionClass;
use ReflectionProperty;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;

/**
 * Build one container-managed object in a unit test, with substitutes.
 *
 * These classes take their dependencies through `#[InjectAs*]` properties and
 * are built by the container with `newInstanceWithoutConstructor()`, so a unit
 * test that wants one in isolation has to do the same. Around forty places in
 * this repository hand-rolled that: a ReflectionClass line, then a
 * ReflectionProperty line per dependency. This is the same thing, named.
 *
 *     $gate = $this->createWithDependencies(OsAdminGate::class, [
 *         'policy' => $policy,
 *     ]);
 *
 * Static, so a data provider or a static helper can call it as well; `$this->`
 * still resolves a static method, which leaves every existing call site alone.
 *
 * It refuses anything that is NOT container-managed, and that refusal is the
 * point rather than a limitation. On 2026-09-10 two PlatformUser tests broke
 * because they assembled a DOMAIN MODEL this way: the model gained a property,
 * the reflection-built instances left it uninitialized, and the tests failed on
 * a change that was correct. A domain model, DTO, payload or resource has a
 * real constructor and must be built with it — then adding a required field
 * breaks compilation at every call site, which is the whole reason it has one.
 *
 * Reflection is for substituting collaborators. It is not an assembly line.
 */
trait BuildsContainerManagedObjects
{
    /** @var list<class-string> */
    private static array $containerManagedAttributes = [
        AsService::class,
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        AsServerLifecycleListener::class,
        AsCommand::class,
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
    ];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $dependencies Property name => substitute.
     * @return T
     */
    protected static function createWithDependencies(string $class, array $dependencies = []): object
    {
        $reflection = new ReflectionClass($class);

        if (!self::isContainerManaged($reflection)) {
            self::fail(sprintf(
                '%s is not container-managed, so it must not be built by reflection. It has a '
                . 'constructor — call it. Building it this way leaves any property the '
                . 'constructor would have set uninitialized, and the test then fails the next '
                . 'time someone adds a field, on a change that was correct.',
                $class,
            ));
        }

        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($dependencies as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                self::fail(sprintf(
                    '%s has no property $%s. A substitute for a property that does not exist is a '
                    . 'test asserting against a class shape that changed underneath it.',
                    $class,
                    $name,
                ));
            }

            $property = new ReflectionProperty($class, $name);
            $property->setValue($instance, $value);
        }

        // The container calls this after injection, so a test that skips it is
        // exercising a state the runtime never produces.
        if ($instance instanceof InitializesAfterInjectionInterface) {
            $instance->initialize();
        }

        return $instance;
    }

    /** @param ReflectionClass<object> $reflection */
    private static function isContainerManaged(ReflectionClass $reflection): bool
    {
        foreach (self::$containerManagedAttributes as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        // #[AsRepository] lives in semitexa/orm, which this package does not
        // depend on. Matched by name so the trait stays usable in an install
        // without the ORM rather than fataling on a missing class.
        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() === 'Semitexa\\Orm\\Attribute\\AsRepository') {
                return true;
            }
        }

        return false;
    }
}

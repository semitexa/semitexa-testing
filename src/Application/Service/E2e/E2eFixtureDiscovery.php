<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Service\E2e;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Testing\Attributes\AsE2eFixture;
use Semitexa\Testing\Contract\E2eFixtureInterface;

/**
 * Every #[AsE2eFixture] of the installed packages and the project, in the order
 * they run: ascending priority, then id. A marked class that is no fixture, an
 * empty id and an id two classes declare are refused, before any fixture runs.
 */
final class E2eFixtureDiscovery
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
    }

    /** @return list<array{id: string, priority: int, class: class-string<E2eFixtureInterface>}> */
    public function discover(): array
    {
        $fixtures = self::ordered($this->classDiscovery->findClassesWithAttribute(AsE2eFixture::class));
        // A class whose file failed to load is in no answer of findClassesWithAttribute(): a fixture
        // among them would just be missing while the others ran (review, testing#40). Its source
        // says whether it is one; an unreadable source may be, so it is refused too.
        $map = $this->classDiscovery->getClassMap();
        foreach ($this->classDiscovery->unloadableClasses() as $class => $why) {
            $file = $map[$class] ?? null;
            $source = is_string($file) && is_file($file) ? @file_get_contents($file) : false;
            if ($source === false || str_contains($source, 'AsE2eFixture')) {
                throw new \LogicException(sprintf('E2E fixture %s could not be loaded (%s): no fixture runs while one is missing.', $class, $why));
            }
        }

        return $fixtures;
    }

    /**
     * @param list<string> $classes
     * @return list<array{id: string, priority: int, class: class-string<E2eFixtureInterface>}>
     */
    public static function ordered(array $classes): array
    {
        $fixtures = [];
        $seen = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \LogicException(sprintf('E2E fixture %s cannot be loaded.', $class));
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }
            if (!$ref->implementsInterface(E2eFixtureInterface::class)) {
                throw new \LogicException(sprintf('%s is marked #[AsE2eFixture] but does not implement %s.', $class, E2eFixtureInterface::class));
            }
            // e2e:fixtures makes it with `new $class()`: a constructor that cannot be called so would
            // fail after earlier fixtures had written (review, testing#40).
            $constructor = $ref->getConstructor();
            if ($constructor !== null && (!$constructor->isPublic() || $constructor->getNumberOfRequiredParameters() > 0)) {
                throw new \LogicException(sprintf('E2E fixture %s must be constructible with no arguments.', $class));
            }
            $attributes = $ref->getAttributes(AsE2eFixture::class);
            if ($attributes === []) {
                continue;
            }
            $attribute = $attributes[0]->newInstance();
            $id = trim($attribute->id);
            if ($id === '') {
                throw new \LogicException(sprintf('E2E fixture %s declares an empty id.', $class));
            }
            if (isset($seen[$id])) {
                throw new \LogicException(sprintf("E2E fixture id '%s' is declared by both %s and %s.", $id, $seen[$id], $class));
            }
            $seen[$id] = $class;
            /** @var class-string<E2eFixtureInterface> $class */
            $fixtures[] = ['id' => $id, 'priority' => $attribute->priority, 'class' => $class];
        }
        usort($fixtures, static fn (array $a, array $b): int => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);

        return $fixtures;
    }
}

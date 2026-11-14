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
        return self::ordered($this->classDiscovery->findClassesWithAttribute(AsE2eFixture::class));
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

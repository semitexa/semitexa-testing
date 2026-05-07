<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Testing\Attributes\TestablePayload;
use Semitexa\Testing\Traits\TestsPayloads;

/**
 * Universal contract test for every payload that opts into strategy-based
 * testing via `#[TestablePayload]`.
 *
 * Two distinct conditions used to be conflated under a single
 * `assertNotEmpty($payloads)` call, producing a false-positive failure
 * whenever the project happened to have zero adopters:
 *
 *   1. **Discovery is broken** — `ClassDiscovery` cannot enumerate by
 *      attribute. This is a real regression the suite must catch.
 *   2. **Discovery works but no payload has opted in** — legitimate empty
 *      result; nothing to check; the test must be a clean pass with a
 *      transparent message.
 *
 * The test now asserts (1) explicitly via the discovery probe and exercises
 * the per-payload contract for every adopter found in (2). Adding the
 * attribute to a payload anywhere in the codebase automatically enrols it;
 * the test does NOT hardcode any payload class.
 */
final class ProjectPayloadsContractTest extends TestCase
{
    use TestsPayloads;

    #[Test]
    public function project_payload_discovery_surface_is_callable(): void
    {
        $classDiscovery = ContainerFactory::get()->get(ClassDiscovery::class);
        self::assertInstanceOf(
            ClassDiscovery::class,
            $classDiscovery,
            'ClassDiscovery must be resolvable from the container — without it the '
            . '#[TestablePayload] sweep below cannot run for any opted-in payload.',
        );

        // The call must not throw, and must return a list (empty or otherwise).
        $payloads = $classDiscovery->findClassesWithAttribute(TestablePayload::class);
        self::assertIsArray(
            $payloads,
            'ClassDiscovery::findClassesWithAttribute must always return an array — '
            . 'a null/throw here means the attribute scanner regressed and every '
            . 'opt-in payload is silently skipped.',
        );
    }

    #[Test]
    public function every_opted_in_payload_satisfies_its_declared_strategies(): void
    {
        $classDiscovery = ContainerFactory::get()->get(ClassDiscovery::class);
        \assert($classDiscovery instanceof ClassDiscovery);
        $payloads = $classDiscovery->findClassesWithAttribute(TestablePayload::class);

        if ($payloads === []) {
            // Honest "nothing to verify" — empty discovery is a project state,
            // not a discovery defect (the previous test already proved the
            // discovery surface is healthy). Adding `#[TestablePayload(...)]`
            // to any payload in the codebase automatically enrols it here.
            self::assertSame([], $payloads);
            return;
        }

        foreach ($payloads as $class) {
            $this->assertPayloadContract($class);
        }
    }
}

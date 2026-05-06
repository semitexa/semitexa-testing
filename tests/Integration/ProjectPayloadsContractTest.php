<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Testing\Attributes\TestablePayload;
use Semitexa\Testing\Traits\TestsPayloads;

/**
 * Universal Contract Test: Automatically discovers and verifies ALL payloads
 * in the project and its packages that have the #[TestablePayload] attribute.
 *
 * This replaces the need for individual payload test files.
 */
class ProjectPayloadsContractTest extends TestCase
{
    use TestsPayloads;

    public function test_discovered_payloads_follow_contract(): void
    {
        /** @var ClassDiscovery $classDiscovery */
        $classDiscovery = ContainerFactory::get()->get(ClassDiscovery::class);
        $payloads = $classDiscovery->findClassesWithAttribute(TestablePayload::class);

        foreach ($payloads as $class) {
            $this->assertPayloadContract($class);
        }

        $this->assertIsArray($payloads);
    }
}

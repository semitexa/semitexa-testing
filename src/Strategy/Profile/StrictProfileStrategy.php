<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy\Profile;

use Semitexa\Testing\Contract\TestingProfileInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\Strategy\HttpMethodStrategy;
use Semitexa\Testing\Strategy\SecurityStrategy;
use Semitexa\Testing\Strategy\TypeEnforcementStrategy;

/**
 * Strict profile: Standard + aggressive type mutation testing.
 *
 * Forces type_mutation to true even if context overrides it to false.
 * Inject context override via PayloadContractTester before expansion.
 */
final class StrictProfileStrategy implements TestingProfileInterface
{
    public function getStrategyClasses(): array
    {
        return [
            SecurityStrategy::class,
            HttpMethodStrategy::class,
            TypeEnforcementStrategy::class, // type_mutation defaults to true
        ];
    }

    public function canRun(PayloadMetadata $metadata): bool
    {
        return false;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return 'StrictProfileStrategy is a profile — it is always expanded into sub-strategies.';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        return [];
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void {}
}

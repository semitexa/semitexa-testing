<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy\Profile;

use Semitexa\Testing\Contract\TestingProfileInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\Strategy\HttpMethodStrategy;
use Semitexa\Testing\Strategy\MonkeyTestingStrategy;
use Semitexa\Testing\Strategy\SecurityStrategy;
use Semitexa\Testing\Strategy\TypeEnforcementStrategy;

/**
 * Paranoid profile: everything — Standard + type mutations + MonkeyTesting.
 * Maximum coverage. Use for critical endpoints (auth, payments, data mutation).
 */
final class ParanoidProfileStrategy implements TestingProfileInterface
{
    public function getStrategyClasses(): array
    {
        return [
            SecurityStrategy::class,
            HttpMethodStrategy::class,
            TypeEnforcementStrategy::class,
            MonkeyTestingStrategy::class,
        ];
    }

    public function canRun(PayloadMetadata $metadata): bool
    {
        return false;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return 'ParanoidProfileStrategy is a profile — it is always expanded into sub-strategies.';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        return [];
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void {}
}

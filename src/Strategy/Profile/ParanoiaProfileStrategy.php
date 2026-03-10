<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy\Profile;

use Semitexa\Testing\Contract\TestingProfileInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\Strategy\CoroutineIsolationStrategy;
use Semitexa\Testing\Strategy\MemoryLeakStrategy;
use Semitexa\Testing\Strategy\MonkeyTestingStrategy;

/**
 * Paranoia Profile: The ultimate testing confidence level for Semitexa modules.
 *
 * Includes:
 * - StandardProfile (Auth, Methods, Type Enforcement)
 * - MonkeyTesting (Random data stress-test)
 * - MemoryLeakTesting (Long-run stability and leak detection)
 * - CoroutineIsolation (Cross-request and auth context data leak detection)
 *
 * Use this level for core modules (User, Auth, Billing) to ensure absolute stability.
 */
final class ParanoiaProfileStrategy implements TestingProfileInterface
{
    public function getStrategyClasses(): array
    {
        return [
            StandardProfileStrategy::class,
            MonkeyTestingStrategy::class,
            MemoryLeakStrategy::class,
            CoroutineIsolationStrategy::class,
        ];
    }

    public function canRun(PayloadMetadata $metadata): bool
    {
        return false; // Expanded before execution
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return 'ParanoiaProfileStrategy is a profile.';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        return [];
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
    }
}

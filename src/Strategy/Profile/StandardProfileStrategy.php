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
 * Standard profile: covers auth, HTTP methods, and required-field validation.
 *
 * TypeEnforcementStrategy runs in "required fields only" mode — type mutations
 * are included (controlled by context['type_mutation']).
 */
final class StandardProfileStrategy implements TestingProfileInterface
{
    public function getStrategyClasses(): array
    {
        return [
            SecurityStrategy::class,
            HttpMethodStrategy::class,
            TypeEnforcementStrategy::class,
        ];
    }

    // --- Delegation stubs (profiles are never executed directly) ---

    public function canRun(PayloadMetadata $metadata): bool
    {
        return false; // Always expanded before execution
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return 'StandardProfileStrategy is a profile — it is always expanded into sub-strategies.';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        return [];
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void {}
}

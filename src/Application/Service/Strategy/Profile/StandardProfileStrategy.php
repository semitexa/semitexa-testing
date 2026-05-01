<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Service\Strategy\Profile;

use Semitexa\Testing\Domain\Contract\TestingProfileInterface;
use Semitexa\Testing\Domain\Model\PayloadMetadata;
use Semitexa\Testing\Domain\Model\ResponseResult;
use Semitexa\Testing\Domain\Model\TestCaseDescriptor;
use Semitexa\Testing\Application\Service\Strategy\HttpMethodStrategy;
use Semitexa\Testing\Application\Service\Strategy\SecurityStrategy;
use Semitexa\Testing\Application\Service\Strategy\TypeEnforcementStrategy;

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

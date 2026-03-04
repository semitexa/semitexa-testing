<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy;

use PHPUnit\Framework\Assert;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Verifies that HTTP methods not listed in #[AsPayload(methods:...)] return 405.
 */
final class HttpMethodStrategy implements TestingStrategyInterface
{
    private const ALL_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function canRun(PayloadMetadata $metadata): bool
    {
        // Always runnable; if all methods are allowed there are no cases to generate.
        return true;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return '';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $allowed = array_map('strtoupper', $metadata->methods);
        $forbidden = array_diff(self::ALL_METHODS, $allowed);

        // Preferred: 405 Method Not Allowed (strict REST compliance).
        // Fallback accepted: any 4xx — frameworks that don't implement 405 may return 404/401.
        // Rejected: 2xx (method silently accepted) or 5xx (server crash).
        $acceptedStatuses = range(400, 499);

        foreach ($forbidden as $method) {
            yield new TestCaseDescriptor(
                description: "Method {$method} not in allowed list → 4xx",
                method: $method,
                path: $metadata->path,
                headers: [],
                body: null,
                expectedStatus: $acceptedStatuses,
            );
        }
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        Assert::assertContains(
            $response->statusCode,
            $case->expectedStatus,
            "[HttpMethodStrategy] {$case->description}: got {$response->statusCode} (expected 4xx; note: 405 is ideal but 404/401 also accepted)."
        );
    }
}

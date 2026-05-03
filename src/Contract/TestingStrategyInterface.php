<?php

declare(strict_types=1);

namespace Semitexa\Testing\Contract;

use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

interface TestingStrategyInterface
{
    /**
     * Verify that all prerequisites for this strategy are met.
     *
     * If false, the orchestrator reports this strategy as SKIPPED (not FAILED)
     * and moves on to the next strategy. Analogous to PHPUnit's markTestSkipped().
     */
    public function canRun(PayloadMetadata $metadata): bool;

    /**
     * Human-readable reason returned when canRun() is false.
     * Used in test output and failure reports.
     */
    public function skipReason(PayloadMetadata $metadata): string;

    /**
     * Generate the list of test cases to execute for this strategy.
     *
     * @return iterable<TestCaseDescriptor>
     */
    public function generateCases(PayloadMetadata $metadata): iterable;

    /**
     * Assert that the response satisfies the expectations defined in the descriptor.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError when the assertion fails
     */
    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void;
}

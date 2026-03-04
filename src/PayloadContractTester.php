<?php

declare(strict_types=1);

namespace Semitexa\Testing;

use Semitexa\Testing\Contract\TestingProfileInterface;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Data\ContractTestResult;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\StrategyResult;

/**
 * Orchestrates the execution of all declared strategies against a PayloadDTO.
 *
 * Execution model:
 *   - Sequential strategy execution (predictable output order).
 *   - Fail-safe by default: all strategies run even if one fails.
 *   - Fail-fast: configurable via context['fail_fast'] = true.
 *   - Profiles are expanded into sub-strategies before execution (recursive, deduplicated).
 *   - Skipped strategies (canRun() = false) are reported as SKIPPED, not FAILED.
 */
final class PayloadContractTester
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly FailureReporter $reporter,
    ) {}

    public function run(PayloadMetadata $metadata): ContractTestResult
    {
        $failFast = (bool) ($metadata->context['fail_fast'] ?? false);
        $strategies = $this->resolveStrategies($metadata->strategies);
        $results = [];

        foreach ($strategies as $strategyClass) {
            $strategy = new $strategyClass();

            if (!$strategy->canRun($metadata)) {
                $results[] = StrategyResult::skipped($strategyClass, $strategy->skipReason($metadata));
                continue;
            }

            foreach ($strategy->generateCases($metadata) as $case) {
                $response = $this->transport->send($case);

                try {
                    $strategy->assertResponse($case, $response);
                    $results[] = StrategyResult::passed($strategyClass, $case);
                } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                    $this->reporter->report($metadata, $strategy, $case, $response, $e);
                    $results[] = StrategyResult::failed($strategyClass, $case, $e->getMessage());

                    if ($failFast) {
                        return new ContractTestResult($results);
                    }
                }
            }
        }

        return new ContractTestResult($results);
    }

    /**
     * Expand profiles into their constituent strategies recursively.
     * Deduplicates: each strategy class runs at most once.
     *
     * @param list<class-string<TestingStrategyInterface>> $strategyClasses
     * @return list<class-string<TestingStrategyInterface>>
     */
    private function resolveStrategies(array $strategyClasses): array
    {
        $resolved = [];
        $seen = [];

        $expand = function(array $classes) use (&$expand, &$resolved, &$seen): void {
            foreach ($classes as $class) {
                if (isset($seen[$class])) {
                    continue;
                }
                $seen[$class] = true;

                if (!class_exists($class)) {
                    continue;
                }

                $instance = new $class();
                if ($instance instanceof TestingProfileInterface) {
                    // Expand profile into sub-strategies (recursive)
                    $expand($instance->getStrategyClasses());
                } else {
                    $resolved[] = $class;
                }
            }
        };

        $expand($strategyClasses);
        return $resolved;
    }
}

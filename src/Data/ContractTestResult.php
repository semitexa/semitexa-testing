<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

final class ContractTestResult
{
    /** @param list<StrategyResult> $results */
    public function __construct(private readonly array $results) {}

    /** @return list<StrategyResult> */
    public function getFailures(): array
    {
        return array_values(array_filter(
            $this->results,
            fn(StrategyResult $r) => $r->status === StrategyResultStatus::Failed,
        ));
    }

    /** @return list<StrategyResult> */
    public function getSkipped(): array
    {
        return array_values(array_filter(
            $this->results,
            fn(StrategyResult $r) => $r->status === StrategyResultStatus::Skipped,
        ));
    }

    /** @return list<StrategyResult> */
    public function getPassed(): array
    {
        return array_values(array_filter(
            $this->results,
            fn(StrategyResult $r) => $r->status === StrategyResultStatus::Passed,
        ));
    }

    /** @return list<StrategyResult> */
    public function all(): array
    {
        return $this->results;
    }

    public function hasFailures(): bool
    {
        return count($this->getFailures()) > 0;
    }
}

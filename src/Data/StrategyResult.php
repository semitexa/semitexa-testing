<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

enum StrategyResultStatus
{
    case Passed;
    case Failed;
    case Skipped;
}

final readonly class StrategyResult
{
    private function __construct(
        public StrategyResultStatus $status,
        public string $strategyClass,
        public ?TestCaseDescriptor $case,
        public string $message,
    ) {}

    public static function passed(string $strategyClass, TestCaseDescriptor $case): self
    {
        return new self(StrategyResultStatus::Passed, $strategyClass, $case, '');
    }

    public static function failed(string $strategyClass, TestCaseDescriptor $case, string $message): self
    {
        return new self(StrategyResultStatus::Failed, $strategyClass, $case, $message);
    }

    public static function skipped(string $strategyClass, string $reason): self
    {
        return new self(StrategyResultStatus::Skipped, $strategyClass, null, $reason);
    }
}

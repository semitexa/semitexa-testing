<?php

declare(strict_types=1);

namespace Semitexa\Testing\Domain\Enum;

enum StrategyResultStatus
{
    case Passed;
    case Failed;
    case Skipped;
}

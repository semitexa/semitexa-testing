<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

enum StrategyResultStatus
{
    case Passed;
    case Failed;
    case Skipped;
}

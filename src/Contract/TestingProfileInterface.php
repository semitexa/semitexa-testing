<?php

declare(strict_types=1);

namespace Semitexa\Testing\Contract;

use Semitexa\Testing\Data\PayloadMetadata;

/**
 * A profile is a named collection of strategies (a bundle / meta-strategy).
 *
 * Profiles are expanded by PayloadContractTester before execution:
 * the profile class itself never has generateCases() or assertResponse() called —
 * only its constituent strategies do.
 *
 * Deduplication: if two profiles share a base strategy, it runs only once.
 */
interface TestingProfileInterface extends TestingStrategyInterface
{
    /**
     * Return the strategy class names this profile aggregates.
     * May include other profile class names (recursive expansion is supported).
     *
     * @return list<class-string<TestingStrategyInterface>>
     */
    public function getStrategyClasses(): array;
}

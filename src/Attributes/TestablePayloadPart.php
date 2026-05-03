<?php

declare(strict_types=1);

namespace Semitexa\Testing\Attributes;

use Attribute;

/**
 * Declares testing strategies for an #[AsPayloadPart] trait or helper class.
 *
 * Strategies declared here are automatically merged into any PayloadDTO that
 * uses this trait, with deduplication applied across all sources.
 *
 * Example:
 * ```php
 * #[AsPayloadPart(base: PaginationPart::class)]
 * #[TestablePayloadPart(strategies: [PaginationBoundsStrategy::class])]
 * trait PaginationTrait
 * {
 *     public int $page = 1;
 *     public int $perPage = 25;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TestablePayloadPart
{
    /**
     * @param list<class-string<\Semitexa\Testing\Contract\TestingStrategyInterface>> $strategies
     */
    public function __construct(
        public readonly array $strategies = [],
    ) {}
}

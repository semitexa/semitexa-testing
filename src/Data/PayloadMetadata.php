<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

/**
 * Pre-populated metadata for a PayloadDTO, built via Reflection by PayloadMetadataFactory.
 *
 * IMPORTANT LIMITATION: Only type-level information is available via Reflection.
 * Business constraints encoded in validate() method bodies (min/max length, email
 * format, enum values) are NOT captured here. Strategies operate on type-level only.
 */
final readonly class PayloadMetadata
{
    /**
     * @param class-string                                $payloadClass
     * @param list<string>                                $methods       Allowed HTTP methods from #[AsPayload]
     * @param list<PropertyMeta>                          $properties    Reflected public properties
     * @param array<string, mixed>                        $context       Raw context from #[TestablePayload]
     * @param list<class-string<\Semitexa\Testing\Contract\TestingStrategyInterface>> $strategies Merged (payload + parts), deduplicated
     */
    public function __construct(
        public string $payloadClass,
        public string $path,
        public array $methods,
        public bool $requiresAuth,
        public array $properties,
        public array $context,
        public array $strategies,
    ) {}
}

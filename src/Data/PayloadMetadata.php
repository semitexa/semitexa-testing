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
final class PayloadMetadata
{
    /**
     * Derived field: protected endpoints require authentication.
     * Under the authorization model, requiresAuth === !isPublic.
     */
    public readonly bool $requiresAuth;

    /**
     * @param class-string                                $payloadClass
     * @param list<string>                                $methods       Allowed HTTP methods from #[AsPayload]
     * @param list<PropertyMeta>                          $properties    Reflected public properties
     * @param array<string, mixed>                        $context       Raw context from #[TestablePayload]
     * @param list<class-string<\Semitexa\Testing\Contract\TestingStrategyInterface>> $strategies Merged (payload + parts), deduplicated
     */
    public function __construct(
        public readonly string $payloadClass,
        public readonly string $path,
        public readonly array $methods,
        public readonly bool $isPublic,
        public readonly array $properties,
        public readonly array $context,
        public readonly array $strategies,
    ) {
        $this->requiresAuth = !$isPublic;
    }
}

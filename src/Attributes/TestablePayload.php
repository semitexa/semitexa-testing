<?php

declare(strict_types=1);

namespace Semitexa\Testing\Attributes;

use Attribute;

/**
 * Declares testing strategies for a PayloadDTO class.
 *
 * Intentionally separate from #[AsPayload] to avoid loading test classes
 * during production bootstrap (SRP — routing ≠ testing).
 *
 * Example:
 * ```php
 * #[AsPayload(path: '/api/payments', methods: ['POST'])]
 * #[TestablePayload(
 *     strategies: [ParanoidProfileStrategy::class],
 *     context: [
 *         'auth_header'    => 'Authorization',
 *         'auth_scheme'    => 'Bearer',
 *         'token_provider' => TestTokenProvider::class,
 *     ]
 * )]
 * class PaymentPayload implements PayloadInterface, ValidatablePayload { }
 * ```
 *
 * The `context` map is passed as-is to each strategy via PayloadMetadata::$context.
 * Each strategy reads only the keys it cares about; unknown keys are ignored.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TestablePayload
{
    /**
     * @param list<class-string<\Semitexa\Testing\Contract\TestingStrategyInterface>> $strategies
     * @param array<string, mixed> $context  Per-strategy configuration (auth tokens, flags, etc.)
     */
    public function __construct(
        public readonly array $strategies = [],
        public readonly array $context = [],
    ) {}
}

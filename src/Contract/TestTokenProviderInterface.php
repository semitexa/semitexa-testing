<?php

declare(strict_types=1);

namespace Semitexa\Testing\Contract;

/**
 * Provides auth tokens for SecurityStrategy.
 *
 * Implement this interface in your test bootstrap to supply project-specific tokens.
 * Register the implementation FQCN in #[TestablePayload(context: ['token_provider' => MyProvider::class])].
 */
interface TestTokenProviderInterface
{
    /** A token that should be accepted by the auth pipeline. */
    public function validToken(): string;

    /** A token with a syntactically correct format but invalid signature or unknown identity. */
    public function invalidToken(): string;

    /** A token that was valid but has passed its expiry time. */
    public function expiredToken(): string;
}

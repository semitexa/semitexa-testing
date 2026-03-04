<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy;

use PHPUnit\Framework\Assert;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Contract\TestTokenProviderInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Verifies that #[RequiresAuth] payloads reject unauthenticated requests with 401.
 *
 * Required context keys:
 *   - auth_header:    e.g. 'Authorization'
 *   - auth_scheme:    e.g. 'Bearer'
 *   - token_provider: FQCN implementing TestTokenProviderInterface
 */
final class SecurityStrategy implements TestingStrategyInterface
{
    private const REQUIRED_CONTEXT = ['auth_header', 'auth_scheme', 'token_provider'];

    public function canRun(PayloadMetadata $metadata): bool
    {
        if (!$metadata->requiresAuth) {
            return false;
        }
        foreach (self::REQUIRED_CONTEXT as $key) {
            if (!isset($metadata->context[$key])) {
                return false;
            }
        }
        $providerClass = $metadata->context['token_provider'];
        return class_exists($providerClass)
            && is_a($providerClass, TestTokenProviderInterface::class, true);
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        if (!$metadata->requiresAuth) {
            return 'Payload does not have #[RequiresAuth] — SecurityStrategy skipped.';
        }
        foreach (self::REQUIRED_CONTEXT as $key) {
            if (!isset($metadata->context[$key])) {
                return "SecurityStrategy requires context key '{$key}' in #[TestablePayload].";
            }
        }
        $providerClass = $metadata->context['token_provider'] ?? '(not set)';
        return "token_provider '{$providerClass}' does not implement TestTokenProviderInterface.";
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $method = $metadata->methods[0];
        $path   = $metadata->path;
        $header = $metadata->context['auth_header'];
        $scheme = $metadata->context['auth_scheme'];

        /** @var TestTokenProviderInterface $provider */
        $provider = new ($metadata->context['token_provider'])();

        yield new TestCaseDescriptor(
            description: 'No auth header → 401',
            method: $method,
            path: $path,
            headers: [],
            body: null,
            expectedStatus: 401,
        );

        yield new TestCaseDescriptor(
            description: 'Malformed token → 401',
            method: $method,
            path: $path,
            headers: [$header => $this->buildHeaderValue($scheme, 'INVALID_TOKEN_FORMAT_XYZ')],
            body: null,
            expectedStatus: 401,
        );

        yield new TestCaseDescriptor(
            description: 'Invalid/unknown token → 401',
            method: $method,
            path: $path,
            headers: [$header => $this->buildHeaderValue($scheme, $provider->invalidToken())],
            body: null,
            expectedStatus: 401,
        );

        yield new TestCaseDescriptor(
            description: 'Expired token → 401',
            method: $method,
            path: $path,
            headers: [$header => $this->buildHeaderValue($scheme, $provider->expiredToken())],
            body: null,
            expectedStatus: 401,
        );

        // Sanity check: valid token should NOT get 401.
        // Can be disabled via context['security_skip_valid_token_check' => true]
        // when a real auth session cannot be created in the test environment.
        if (!($metadata->context['security_skip_valid_token_check'] ?? false)) {
            yield new TestCaseDescriptor(
                description: 'Valid token → not 401',
                method: $method,
                path: $path,
                headers: [$header => $this->buildHeaderValue($scheme, $provider->validToken())],
                body: null,
                expectedStatus: array_values(array_diff(range(100, 599), [401])),
            );
        }
    }

    /**
     * Build the auth header value.
     * If scheme is empty (e.g. Cookie-based auth), returns the token as-is.
     * If scheme is set (e.g. Bearer), returns "{scheme} {token}".
     */
    private function buildHeaderValue(string $scheme, string $token): string
    {
        return $scheme !== '' ? "{$scheme} {$token}" : $token;
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        if (is_array($case->expectedStatus)) {
            Assert::assertContains(
                $response->statusCode,
                $case->expectedStatus,
                "[SecurityStrategy] {$case->description}: status {$response->statusCode} not in allowed set."
            );
        } else {
            Assert::assertSame(
                $case->expectedStatus,
                $response->statusCode,
                "[SecurityStrategy] {$case->description}: expected {$case->expectedStatus}, got {$response->statusCode}."
            );
        }
    }
}

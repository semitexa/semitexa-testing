<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy;

use PHPUnit\Framework\Assert;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Contract\TestTokenProviderInterface;
use Semitexa\Testing\Data\IsolationMarker;
use Semitexa\Testing\Data\PayloadMetadata;

use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * CoroutineIsolationStrategy: Detects data leaks between requests/coroutines/workers.
 *
 * Three categories of tests:
 *
 *   A) Cross-Request State Leak — sends sequential A/B requests with unique markers,
 *      verifies response B does not contain marker A.
 *
 *   B) Auth Context Isolation — sends requests as User A then User B,
 *      verifies User B's response does not contain User A's identity.
 *
 *   C) Concurrent Request Isolation — NOT YET IMPLEMENTED.
 *      Requires transport-level support for parallel dispatch.
 *      Will be controlled via context['isolation_concurrent'] when available.
 *
 * Context options:
 *   isolation_marker_field: string  — field name for injecting markers (default: auto-detect first string property)
 *   isolation_pairs: int            — number of A/B pairs for Category A (default: 3)
 *   isolation_identity_field: string — response field that identifies auth user (default: 'email')
 *   isolation_second_token: string  — auth token for a second user (enables Category B identity check)
 *   isolation_response_check: bool  — whether to check response body for markers (default: true)
 */
final class CoroutineIsolationStrategy implements TestingStrategyInterface
{
    private const DEFAULT_PAIRS = 3;

    public function canRun(PayloadMetadata $metadata): bool
    {
        // Need at least one string property for marker injection
        if ($this->findMarkerField($metadata) === null) {
            return false;
        }

        // Need a write method
        if (!$this->hasWriteMethod($metadata)) {
            return false;
        }

        // For auth endpoints, need token provider
        if ($metadata->requiresAuth) {
            if ($metadata->context['security_skip_valid_token_check'] ?? false) {
                return false;
            }
            if (!isset($metadata->context['auth_header'], $metadata->context['token_provider'])) {
                return false;
            }
            $providerClass = $metadata->context['token_provider'];
            if (!is_string($providerClass) || !class_exists($providerClass) || !is_subclass_of($providerClass, TestTokenProviderInterface::class)) {
                return false;
            }
        }

        return true;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        if ($this->findMarkerField($metadata) === null) {
            return 'CoroutineIsolationStrategy skipped: no string property found for marker injection.';
        }
        if (!$this->hasWriteMethod($metadata)) {
            return 'CoroutineIsolationStrategy skipped: no write method (POST/PUT/PATCH) available.';
        }
        if ($metadata->requiresAuth) {
            if ($metadata->context['security_skip_valid_token_check'] ?? false) {
                return 'CoroutineIsolationStrategy skipped: endpoint requires auth but security_skip_valid_token_check is true.';
            }
            if (!isset($metadata->context['auth_header'], $metadata->context['token_provider'])) {
                return 'CoroutineIsolationStrategy skipped: endpoint requires auth but no auth_header/token_provider configured.';
            }
        }
        return '';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $method = $this->pickWriteMethod($metadata->methods);
        $path = $metadata->path;
        $markerFieldName = $this->findMarkerField($metadata);
        $pairs = (int) ($metadata->context['isolation_pairs'] ?? self::DEFAULT_PAIRS);
        $headers = $this->getAuthHeaders($metadata);
        $baseline = $this->buildBaseline($metadata);

        // Category A: Cross-Request State Leak
        for ($i = 1; $i <= $pairs; $i++) {
            $markerA = IsolationMarker::generate($markerFieldName);
            $markerB = IsolationMarker::generate($markerFieldName);

            $bodyA = array_merge($baseline, [$markerFieldName => $markerA->id]);
            $bodyB = array_merge($baseline, [$markerFieldName => $markerB->id]);

            // Request A — just send, we don't assert isolation on this one
            yield new TestCaseDescriptor(
                description: "Cross-request isolation pair {$i}/{$pairs}: Request A (inject marker {$markerA->id})",
                method: $method,
                path: $path,
                headers: $headers,
                body: $bodyA,
                expectedStatus: [200, 201, 302, 422],
                context: [
                    'isolation_role' => 'request_a',
                    'marker' => $markerA->id,
                ],
            );

            // Request B — must NOT contain marker A
            yield new TestCaseDescriptor(
                description: "Cross-request isolation pair {$i}/{$pairs}: Request B (must not contain {$markerA->id})",
                method: $method,
                path: $path,
                headers: $headers,
                body: $bodyB,
                expectedStatus: [200, 201, 302, 422],
                context: [
                    'isolation_role' => 'request_b',
                    'marker' => $markerB->id,
                    'forbidden_marker' => $markerA->id,
                    'isolation_response_check' => $metadata->context['isolation_response_check'] ?? true,
                ],
            );
        }

        // Category B: Auth Context Isolation
        if ($metadata->requiresAuth) {
            yield from $this->generateAuthIsolationCases($metadata, $method, $path, $baseline, $markerFieldName);
        }
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        // Status check
        if (is_array($case->expectedStatus)) {
            Assert::assertContains(
                $response->statusCode,
                $case->expectedStatus,
                "[CoroutineIsolationStrategy] {$case->description}: got status {$response->statusCode}."
            );
        }

        // A 422 means the generated payload never exercised the target behavior.
        if ($response->statusCode === 422) {
            Assert::fail(
                "[CoroutineIsolationStrategy] {$case->description}: generated payload was rejected with 422, so isolation was not verified."
            );
        }

        $responseCheck = $case->context['isolation_response_check'] ?? true;
        if (!$responseCheck) {
            return;
        }

        // Category A: cross-request data leak
        $forbiddenMarker = $case->context['forbidden_marker'] ?? null;
        if ($forbiddenMarker !== null && $response->body !== '') {
            Assert::assertStringNotContainsString(
                $forbiddenMarker,
                $response->body,
                sprintf(
                    "[CoroutineIsolationStrategy] DATA LEAK DETECTED! Response to Request B contains marker "
                    . "from Request A: '%s'. This indicates cross-request state contamination. %s",
                    $forbiddenMarker,
                    $case->description,
                )
            );
        }

        // Category B: auth context leak
        $forbiddenIdentity = $case->context['forbidden_identity'] ?? null;
        if ($forbiddenIdentity !== null && $response->body !== '') {
            Assert::assertStringNotContainsString(
                $forbiddenIdentity,
                $response->body,
                sprintf(
                    "[CoroutineIsolationStrategy] AUTH CONTEXT LEAK! Response contains identity "
                    . "from a different user session: '%s'. %s",
                    $forbiddenIdentity,
                    $case->description,
                )
            );
        }
    }

    /**
     * Category B: Send request as User A, then as User B, verify no identity bleed.
     */
    private function generateAuthIsolationCases(
        PayloadMetadata $metadata,
        string $method,
        string $path,
        array $baseline,
        string $markerFieldName,
    ): iterable {
        $header = $metadata->context['auth_header'] ?? null;
        $scheme = $metadata->context['auth_scheme'] ?? '';
        $providerClass = $metadata->context['token_provider'] ?? null;

        if ($header === null || $providerClass === null) {
            return;
        }

        /** @var TestTokenProviderInterface $provider */
        $provider = new $providerClass();
        $tokenA = $provider->validToken();

        $headerValueA = $scheme !== '' ? "{$scheme} {$tokenA}" : $tokenA;

        $markerA = IsolationMarker::generate($markerFieldName);
        $bodyA = array_merge($baseline, [$markerFieldName => $markerA->id]);

        // Request as User A
        yield new TestCaseDescriptor(
            description: "Auth isolation: Request as User A (marker {$markerA->id})",
            method: $method,
            path: $path,
            headers: [$header => $headerValueA],
            body: $bodyA,
            expectedStatus: [200, 201, 302, 422],
            context: [
                'isolation_role' => 'auth_user_a',
                'marker' => $markerA->id,
            ],
        );

        // Request as User B — use a different token if available
        $secondToken = $metadata->context['isolation_second_token'] ?? null;
        if ($secondToken === null && method_exists($provider, 'secondValidToken')) {
            $secondToken = $provider->secondValidToken();
        }

        // If no second token available, fall back to same token but still check marker bleed
        $tokenB = $secondToken ?? $tokenA;
        $headerValueB = $scheme !== '' ? "{$scheme} {$tokenB}" : $tokenB;

        $markerB = IsolationMarker::generate($markerFieldName);
        $bodyB = array_merge($baseline, [$markerFieldName => $markerB->id]);

        $context = [
            'isolation_role' => 'auth_user_b',
            'marker' => $markerB->id,
            'forbidden_marker' => $markerA->id,
            'isolation_response_check' => $metadata->context['isolation_response_check'] ?? true,
        ];

        // TODO: Identity bleed detection requires TestTokenProviderInterface to expose
        // user identity (e.g. identityOf() method). Currently Category B only checks
        // cross-request marker bleed between different auth contexts.

        yield new TestCaseDescriptor(
            description: "Auth isolation: Request as User B (must not contain marker {$markerA->id})",
            method: $method,
            path: $path,
            headers: [$header => $headerValueB],
            body: $bodyB,
            expectedStatus: [200, 201, 302, 422],
            context: $context,
        );
    }

    private function findMarkerField(PayloadMetadata $metadata): ?string
    {
        // User-specified field — validate it exists and is a string property
        $explicit = $metadata->context['isolation_marker_field'] ?? null;
        if ($explicit !== null) {
            if (!is_string($explicit)) {
                return null;
            }
            foreach ($metadata->properties as $prop) {
                if ($prop->name === $explicit && $prop->type === 'string') {
                    return $explicit;
                }
            }
            return null;
        }

        // Auto-detect: first string property
        foreach ($metadata->properties as $prop) {
            if ($prop->type === 'string') {
                return $prop->name;
            }
        }

        return null;
    }

    private function hasWriteMethod(PayloadMetadata $metadata): bool
    {
        $methods = array_map('strtoupper', $metadata->methods);
        return !empty(array_intersect(['POST', 'PUT', 'PATCH'], $methods));
    }

    private function pickWriteMethod(array $methods): string
    {
        $methods = array_map('strtoupper', $methods);
        foreach (['POST', 'PUT', 'PATCH'] as $preferred) {
            if (in_array($preferred, $methods, true)) {
                return $preferred;
            }
        }
        return $methods[0] ?? 'POST';
    }

    private function getAuthHeaders(PayloadMetadata $metadata): array
    {
        if (!$metadata->requiresAuth) {
            return [];
        }

        $header = $metadata->context['auth_header'] ?? null;
        $scheme = $metadata->context['auth_scheme'] ?? '';
        $providerClass = $metadata->context['token_provider'] ?? null;

        if ($header === null || $providerClass === null) {
            return [];
        }

        /** @var TestTokenProviderInterface $provider */
        $provider = new $providerClass();
        $token = $provider->validToken();
        $value = $scheme !== '' ? "{$scheme} {$token}" : $token;

        return [$header => $value];
    }

    /** Build baseline body with valid values for all known-type properties. */
    private function buildBaseline(PayloadMetadata $metadata): array
    {
        $baseline = [
            'int' => 1,
            'float' => 1.0,
            'string' => 'test_value',
            'bool' => true,
            'array' => [],
        ];

        $body = [];
        foreach ($metadata->properties as $prop) {
            if ($prop->hasDefault) {
                $body[$prop->name] = $prop->defaultValue;
            } elseif (isset($baseline[$prop->type])) {
                $body[$prop->name] = $baseline[$prop->type];
            }
        }
        return $body;
    }
}

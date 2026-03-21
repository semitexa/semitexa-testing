<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy;

use PHPUnit\Framework\Assert;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\PropertyMeta;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Verifies type enforcement and required-field validation.
 *
 * Two test families:
 *   1. Missing required fields (non-nullable, no default) → 422
 *   2. Wrong type mutations (string sent for int, array sent for string, etc.) → 422
 *
 * Family 2 requires InProcessTransport with strict mode enabled in RequestDtoHydrator.
 * The InProcessTransport enables strict mode automatically; this strategy always runs.
 *
 * Mutation mode can be disabled via context: ['type_mutation' => false]
 */
final class TypeEnforcementStrategy implements TestingStrategyInterface
{
    /** Cached valid token so validToken() is only called once per strategy run. */
    private ?string $cachedValidToken = null;
    /** Valid baseline values by type — used to construct mutation payloads. */
    private const BASELINE = [
        'int'    => 1,
        'float'  => 1.0,
        'string' => 'test_value',
        'bool'   => true,
        'array'  => [],
    ];

    /** Wrong-type mutations to inject per target type. */
    private const MUTATIONS = [
        'int'    => ['not_a_number', [1, 2, 3]],
        'float'  => ['not_a_float', [1.0]],
        'string' => [[1, 2, 3]],
        'bool'   => ['maybe', [true, false]],
        'array'  => ['a_string', 42],
    ];

    public function canRun(PayloadMetadata $metadata): bool
    {
        // For protected endpoints, we need valid auth headers to get past the auth check
        // and actually reach the type validation layer.
        // If auth is required but the token provider signals it can't produce a real valid
        // token (security_skip_valid_token_check = true), skip this strategy.
        if ($metadata->requiresAuth) {
            if ($metadata->context['security_skip_valid_token_check'] ?? false) {
                return false; // No real valid token available; cannot reach type enforcement
            }
            if (!isset($metadata->context['auth_header'], $metadata->context['token_provider'])) {
                return false; // Auth required but no auth config — cannot authenticate
            }
        }
        return true;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        if ($metadata->requiresAuth) {
            if ($metadata->context['security_skip_valid_token_check'] ?? false) {
                return 'TypeEnforcementStrategy skipped: endpoint requires auth but security_skip_valid_token_check is true (no real valid token available). Implement SessionTestTokenProvider::validToken() to enable.';
            }
            return 'TypeEnforcementStrategy skipped: endpoint requires auth but no auth_header/token_provider configured in context.';
        }
        return '';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $method  = $this->pickWriteMethod($metadata->methods);
        $path    = $metadata->path;
        $headers = $this->getAuthHeaders($metadata);

        $required = $this->getRequiredProperties($metadata);
        $baseline = $this->buildBaseline($metadata);

        // Family 1: Missing required fields
        foreach ($required as $prop) {
            $body = $baseline;
            unset($body[$prop->name]);

            yield new TestCaseDescriptor(
                description: "Missing required field '{$prop->name}' → 422",
                method: $method,
                path: $path,
                headers: $headers,
                body: $body,
                expectedStatus: 422,
            );
        }

        // Family 2: Type mutations (only for known primitive types)
        if ($metadata->context['type_mutation'] ?? true) {
            foreach ($metadata->properties as $prop) {
                $mutations = self::MUTATIONS[$prop->type] ?? null;
                if ($mutations === null) {
                    continue; // Object/union types — skip
                }
                foreach ($mutations as $badValue) {
                    $body    = array_merge($baseline, [$prop->name => $badValue]);
                    $badType = is_array($badValue) ? 'array' : gettype($badValue);

                    yield new TestCaseDescriptor(
                        description: "Field '{$prop->name}' as {$badType} (expected {$prop->type}) → 422",
                        method: $method,
                        path: $path,
                        headers: $headers,
                        body: $body,
                        expectedStatus: 422,
                    );
                }
            }
        }
    }

    /**
     * Build auth headers for the generated cases.
     * Returns [] for public endpoints. For protected endpoints, obtains a valid
     * token from the configured token_provider (cached for the lifetime of this strategy run).
     */
    private function getAuthHeaders(PayloadMetadata $metadata): array
    {
        if (!$metadata->requiresAuth) {
            return [];
        }

        $header        = $metadata->context['auth_header'] ?? null;
        $scheme        = $metadata->context['auth_scheme'] ?? '';
        $providerClass = $metadata->context['token_provider'] ?? null;

        if ($header === null || $providerClass === null) {
            return [];
        }

        if ($this->cachedValidToken === null) {
            $provider                = new $providerClass();
            $this->cachedValidToken  = $provider->validToken();
        }

        $value = $scheme !== '' ? "{$scheme} {$this->cachedValidToken}" : $this->cachedValidToken;
        return [$header => $value];
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        Assert::assertSame(
            $case->expectedStatus,
            $response->statusCode,
            "[TypeEnforcementStrategy] {$case->description}: expected {$case->expectedStatus}, got {$response->statusCode}."
        );
    }

    /** @return list<PropertyMeta> */
    private function getRequiredProperties(PayloadMetadata $metadata): array
    {
        return array_values(array_filter(
            $metadata->properties,
            fn(PropertyMeta $p) => !$p->nullable && !$p->hasDefault,
        ));
    }

    /** Build a baseline body with valid values for all known-type properties. */
    private function buildBaseline(PayloadMetadata $metadata): array
    {
        $body = [];
        foreach ($metadata->properties as $prop) {
            if ($prop->hasDefault) {
                $body[$prop->name] = $prop->defaultValue;
            } elseif (isset(self::BASELINE[$prop->type])) {
                $body[$prop->name] = self::BASELINE[$prop->type];
            }
        }

        $validBody = $metadata->context['valid_body'] ?? null;
        if (is_array($validBody)) {
            return array_merge($body, $validBody);
        }

        return $body;
    }

    private function pickWriteMethod(array $methods): string
    {
        $methods = array_map('strtoupper', $methods);
        foreach (['POST', 'PUT', 'PATCH', 'GET'] as $preferred) {
            if (in_array($preferred, $methods, true)) {
                return $preferred;
            }
        }
        return $methods[0] ?? 'POST';
    }
}

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
 * MemoryLeakStrategy: Detects memory leaks by executing the same payload multiple times.
 *
 * How it works:
 * 1. Warm up (5 iterations) to initialize Twig, Config, and other lazy-loaded services.
 * 2. Measure baseline memory usage.
 * 3. Execute payload N times (e.g., 20).
 * 4. Measure final memory usage after triggering GC.
 * 5. Fail if memory growth exceeds threshold (allowing for minor PHP overhead).
 *
 * Note: Requires InProcessTransport to accurately measure memory within the same worker.
 *
 * Context options:
 *   memory_leak_threshold_bytes: int — override the default growth threshold (default: 4096)
 *   memory_leak_iterations: int — override the default iteration count (default: 20)
 */
final class MemoryLeakStrategy implements TestingStrategyInterface
{
    private const ITERATIONS = 20;
    private const WARMUP_ITERATIONS = 5;
    private const ALLOWED_GROWTH_BYTES = 4096; // 4KB threshold for 20 requests

    public function canRun(PayloadMetadata $metadata): bool
    {
        if (($metadata->context['skip_memory_leak'] ?? false) === true) {
            return false;
        }
        if ($this->usesFixedValidBodyOnWriteEndpoint($metadata)) {
            return false;
        }
        if (empty($metadata->methods)) {
            return false;
        }
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
        if (($metadata->context['skip_memory_leak'] ?? false) === true) {
            return 'MemoryLeakStrategy skipped by payload context.';
        }
        if ($this->usesFixedValidBodyOnWriteEndpoint($metadata)) {
            return 'MemoryLeakStrategy skipped: write endpoints with fixed valid_body replay the same request across iterations and can produce duplicate-validation false positives.';
        }
        if (empty($metadata->methods)) {
            return 'No HTTP methods defined for payload.';
        }
        if ($metadata->requiresAuth) {
            if ($metadata->context['security_skip_valid_token_check'] ?? false) {
                return 'MemoryLeakStrategy skipped: endpoint requires auth but security_skip_valid_token_check is true.';
            }
            if (!isset($metadata->context['auth_header'], $metadata->context['token_provider'])) {
                return 'MemoryLeakStrategy skipped: endpoint requires auth but no auth_header/token_provider configured.';
            }
        }
        return '';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $method = $metadata->methods[0] ?? 'GET';
        $iterations = (int) ($metadata->context['memory_leak_iterations'] ?? self::ITERATIONS);
        $headers = $this->getAuthHeaders($metadata);
        $body = $this->buildBaseline($metadata);

        yield new TestCaseDescriptor(
            description: "Memory stability check ({$iterations} iterations)",
            method: $method,
            path: $metadata->path,
            headers: $headers,
            body: $body,
            expectedStatus: [200, 201, 302, 401, 403],
            context: [
                'memory_leak_check' => true,
                'iterations' => $iterations,
                'warmup' => self::WARMUP_ITERATIONS,
                'memory_leak_threshold_bytes' => (int) ($metadata->context['memory_leak_threshold_bytes'] ?? self::ALLOWED_GROWTH_BYTES),
            ]
        );
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        // Verify HTTP status before interpreting memory stats
        if (is_array($case->expectedStatus)) {
            Assert::assertContains(
                $response->statusCode,
                $case->expectedStatus,
                "[MemoryLeakStrategy] {$case->description}: got status {$response->statusCode}."
            );
        }

        $stats = $response->context['memory_stats'] ?? null;
        if ($stats === null) {
            Assert::fail(
                '[MemoryLeakStrategy] memory_stats not available in response context. '
                . 'Use InProcessTransport for memory leak detection, or disable MemoryLeakStrategy.'
            );
        }

        $baseline = $stats['baseline'] ?? 0;
        $final = $stats['final'] ?? 0;
        $diff = $final - $baseline;
        $threshold = (int) ($case->context['memory_leak_threshold_bytes'] ?? self::ALLOWED_GROWTH_BYTES);
        $iterations = $stats['iterations'] ?? self::ITERATIONS;

        Assert::assertLessThanOrEqual(
            $threshold,
            $diff,
            sprintf(
                "[MemoryLeakStrategy] Potential memory leak detected! Memory grew by %d bytes over %d iterations (Baseline: %d, Final: %d, Threshold: %d).",
                $diff,
                $iterations,
                $baseline,
                $final,
                $threshold,
            )
        );
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

    private function buildBaseline(PayloadMetadata $metadata): ?array
    {
        $baseline = $metadata->context['valid_body'] ?? null;
        if ($baseline === null) {
            return null;
        }

        return is_array($baseline) ? $baseline : null;
    }

    private function usesFixedValidBodyOnWriteEndpoint(PayloadMetadata $metadata): bool
    {
        $validBody = $metadata->context['valid_body'] ?? null;
        if (!is_array($validBody)) {
            return false;
        }

        $methods = array_map('strtoupper', $metadata->methods);
        return !empty(array_intersect(['POST', 'PUT', 'PATCH'], $methods));
    }
}

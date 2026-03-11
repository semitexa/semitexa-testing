<?php

declare(strict_types=1);

namespace Semitexa\Testing\Strategy;

use PHPUnit\Framework\Assert;
use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Sends chaotic / garbage requests and verifies the system returns a safe 4xx,
 * never a 5xx (500, 502, 503, 504).
 *
 * Configurable via context:
 *   monkey_reject_statuses: list<int>  — statuses considered failures (default: 5xx range)
 *   monkey_accept_statuses: list<int>  — statuses considered safe (default: 4xx range)
 *   If neither is set, any non-5xx is considered safe.
 */
final class MonkeyTestingStrategy implements TestingStrategyInterface
{
    private const DEFAULT_REJECT = [500, 502, 503, 504];

    public function canRun(PayloadMetadata $metadata): bool
    {
        return true;
    }

    public function skipReason(PayloadMetadata $metadata): string
    {
        return '';
    }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        $method = $this->pickWriteMethod($metadata->methods);
        $path = $metadata->path;

        yield new TestCaseDescriptor(
            description: 'Huge JSON body (~1 MB) → safe rejection',
            method: $method,
            path: $path,
            headers: ['Content-Type' => 'application/json'],
            body: str_repeat('{"a":"' . str_repeat('x', 100) . '",', 10_000) . '"z":"end"}',
            expectedStatus: $this->safeStatusRange($metadata),
        );

        yield new TestCaseDescriptor(
            description: 'Deeply nested array (depth 500) → safe rejection',
            method: $method,
            path: $path,
            headers: [],
            body: $this->buildDeeplyNested(500),
            expectedStatus: $this->safeStatusRange($metadata),
        );

        yield new TestCaseDescriptor(
            description: 'Malformed JSON → safe rejection',
            method: $method,
            path: $path,
            headers: ['Content-Type' => 'application/json'],
            body: '{not valid json at all ][[]',
            expectedStatus: $this->safeStatusRange($metadata),
        );

        yield new TestCaseDescriptor(
            description: 'SQLi-like string in all fields → safe rejection',
            method: $method,
            path: $path,
            headers: [],
            body: $this->buildSqliPayload($metadata),
            expectedStatus: $this->safeStatusRange($metadata),
        );

        yield new TestCaseDescriptor(
            description: 'Null bytes and control characters → safe rejection',
            method: $method,
            path: $path,
            headers: [],
            body: $this->buildNullBytePayload($metadata),
            expectedStatus: $this->safeStatusRange($metadata),
        );

        yield new TestCaseDescriptor(
            description: 'Extreme Unicode (emoji, RTL, surrogates) → safe rejection',
            method: $method,
            path: $path,
            headers: [],
            body: $this->buildUnicodePayload($metadata),
            expectedStatus: $this->safeStatusRange($metadata),
        );
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        $rejectStatuses = $case->expectedStatus;
        if (is_array($rejectStatuses)) {
            // expectedStatus here is the SAFE set
            Assert::assertContains(
                $response->statusCode,
                $rejectStatuses,
                "[MonkeyTestingStrategy] {$case->description}: got unsafe status {$response->statusCode}."
            );
        } else {
            Assert::assertNotSame(
                $case->expectedStatus,
                $response->statusCode,
                "[MonkeyTestingStrategy] {$case->description}: got status {$response->statusCode}."
            );
        }
    }

    /** @return list<int> Safe (accepted) status codes */
    private function safeStatusRange(PayloadMetadata $metadata): array
    {
        if (isset($metadata->context['monkey_accept_statuses'])) {
            return $metadata->context['monkey_accept_statuses'];
        }
        $reject = $metadata->context['monkey_reject_statuses'] ?? self::DEFAULT_REJECT;
        return array_values(array_diff(range(100, 599), $reject));
    }

    private function buildDeeplyNested(int $depth): array
    {
        $nested = ['leaf' => true];
        for ($i = 0; $i < $depth; $i++) {
            $nested = ['child' => $nested];
        }
        return $nested;
    }

    private function buildSqliPayload(PayloadMetadata $metadata): array
    {
        $sqli = "'; DROP TABLE users; --";
        $body = [];
        foreach ($metadata->properties as $prop) {
            $body[$prop->name] = $sqli;
        }
        return $body ?: ['q' => $sqli];
    }

    private function buildNullBytePayload(PayloadMetadata $metadata): array
    {
        $evil = "normal\x00null\x01\x02\x03byte";
        $body = [];
        foreach ($metadata->properties as $prop) {
            $body[$prop->name] = $evil;
        }
        return $body ?: ['q' => $evil];
    }

    private function buildUnicodePayload(PayloadMetadata $metadata): array
    {
        $unicode = "\u{1F4A9}\u{202E}reversed\u{FEFF}bom\u{D800}";
        $body = [];
        foreach ($metadata->properties as $prop) {
            $body[$prop->name] = $unicode;
        }
        return $body ?: ['q' => $unicode];
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

<?php

declare(strict_types=1);

namespace Semitexa\Testing\Transport;

use Semitexa\Core\Application;
use Semitexa\Core\Request;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Dispatches test requests directly through Application::handleRequest().
 *
 * No network, no Swoole coroutine conflicts.
 * Requests it builds carry Request::$strictHydration = true, so PayloadHydrator
 * runs in strict mode for these requests only. The flag is per-request (no
 * process-global state), so this is safe even if dispatched concurrently.
 *
 * Use this transport for: SecurityStrategy, HttpMethodStrategy,
 * TypeEnforcementStrategy, MonkeyTestingStrategy.
 */
final class InProcessTransport implements TransportInterface
{
    public function __construct(private readonly Application $application) {}

    public function send(TestCaseDescriptor $case): ResponseResult
    {
        $request = $this->buildRequest($case);
        $isMemoryCheck = (bool) ($case->context['memory_leak_check'] ?? false);

        if ($isMemoryCheck) {
            $warmup = $this->intContext($case, 'warmup', 5);
            $iterations = $this->intContext($case, 'iterations', 20);

            // 1. Warm up
            for ($i = 0; $i < $warmup; $i++) {
                $this->application->handleRequest($this->buildRequest($case));
                $this->application->requestScopedContainer->reset();
            }

            gc_collect_cycles();
            $baseline = memory_get_usage();

            // 2. Iterations
            for ($i = 0; $i < $iterations; $i++) {
                $this->application->handleRequest($this->buildRequest($case));
                $this->application->requestScopedContainer->reset();
            }

            gc_collect_cycles();
            $final = memory_get_usage();

            // Final check response (the last one)
            $response = $this->application->handleRequest($request);
            $this->application->requestScopedContainer->reset();

            return new ResponseResult(
                statusCode: $response->statusCode,
                headers: $this->normalizeHeaders($response->headers),
                body: $response->content,
                durationMs: 0,
                context: [
                    'memory_stats' => [
                        'baseline' => $baseline,
                        'final' => $final,
                        'iterations' => $iterations,
                    ],
                ],
            );
        }

        $start = microtime(true);
        try {
            $response = $this->application->handleRequest($request);
        } finally {
            $this->application->requestScopedContainer->reset();
        }
        $durationMs = (microtime(true) - $start) * 1000;

        return new ResponseResult(
            statusCode: $response->statusCode,
            headers: $this->normalizeHeaders($response->headers),
            body: $response->content,
            durationMs: round($durationMs, 2),
        );
    }

    /**
     * Parse "Cookie: name=value; name2=value2" into ['name' => 'value', 'name2' => 'value2'].
     * Application reads session ID via $request->getCookie(), not from raw headers.
     *
     * @return array<string, string>
     */
    private function parseCookieHeader(string $header): array
    {
        if ($header === '') {
            return [];
        }
        $cookies = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            $eqPos = strpos($part, '=');
            if ($eqPos !== false) {
                $cookies[trim(substr($part, 0, $eqPos))] = trim(substr($part, $eqPos + 1));
            }
        }
        return $cookies;
    }

    private function buildRequest(TestCaseDescriptor $case): Request
    {
        $method = strtoupper($case->method);
        $uri = $case->path;

        $headers = $case->headers;
        $content = null;
        $post = [];
        $query = [];

        if ($case->body !== null) {
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if (is_array($case->body)) {
                    // Use JSON_INVALID_UTF8_SUBSTITUTE to allow encoding of chaotic data without throwing JsonException.
                    $content = json_encode($case->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($content === false) {
                        throw new \RuntimeException('Failed to encode request body as JSON.');
                    }
                } elseif (is_scalar($case->body) || $case->body instanceof \Stringable) {
                    $content = (string) $case->body;
                } else {
                    $content = json_encode(
                        $case->body,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                    if ($content === false) {
                        throw new \RuntimeException('Failed to encode non-scalar request body as JSON.');
                    }
                }
                $headers['Content-Type'] ??= 'application/json';
            } elseif ($method === 'GET' && is_array($case->body)) {
                $query = $case->body;
                $uri .= '?' . http_build_query($query);
            }
        }

        return new Request(
            method: $method,
            uri: $uri,
            headers: $headers,
            query: $query,
            post: $post,
            server: [],
            cookies: $this->parseCookieHeader(isset($headers['Cookie']) ? (string) $headers['Cookie'] : ''),
            content: $content,
            strictHydration: true,
        );
    }

    private function intContext(TestCaseDescriptor $case, string $key, int $default): int
    {
        $value = $case->context[$key] ?? $default;
        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string|string[]>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($value)) {
                $normalized[$name] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $strings = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $strings[] = $item;
                }
            }

            $normalized[$name] = $strings;
        }

        return $normalized;
    }
}

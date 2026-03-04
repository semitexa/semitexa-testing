<?php

declare(strict_types=1);

namespace Semitexa\Testing\Transport;

use Semitexa\Core\Application;
use Semitexa\Core\Http\RequestDtoHydrator;
use Semitexa\Core\Request;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Dispatches test requests directly through Application::handleRequest().
 *
 * No network, no Swoole coroutine conflicts.
 * Enables strict hydration mode for the duration of each request.
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

        RequestDtoHydrator::enableStrictMode(true);
        $start = microtime(true);
        try {
            $response = $this->application->handleRequest($request);
        } finally {
            RequestDtoHydrator::enableStrictMode(false);
        }
        $durationMs = (microtime(true) - $start) * 1000;

        return new ResponseResult(
            statusCode: $response->statusCode,
            headers: $response->headers,
            body: $response->content,
            durationMs: round($durationMs, 2),
        );
    }

    /**
     * Parse "Cookie: name=value; name2=value2" into ['name' => 'value', 'name2' => 'value2'].
     * Application reads session ID via $request->getCookie(), not from raw headers.
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
                $content = is_array($case->body)
                    ? json_encode($case->body, JSON_THROW_ON_ERROR)
                    : (string) $case->body;
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
            cookies: $this->parseCookieHeader($headers['Cookie'] ?? ''),
            content: $content,
        );
    }
}

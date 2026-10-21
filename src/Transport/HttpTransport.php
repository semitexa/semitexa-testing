<?php

declare(strict_types=1);

namespace Semitexa\Testing\Transport;

use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\RetryPolicy;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Dispatches test requests via real HTTP to a running Swoole test server.
 *
 * Use for integration tests where HTTP-level behavior matters (PerformanceStrategy, etc.).
 * Base URL is configured in the PHPUnit extension parameters.
 *
 * TODO: Implementation planned for roadmap step 14.
 */
final class HttpTransport implements TransportInterface
{
    private readonly HttpClientInterface $http;

    public function __construct(private readonly string $baseUrl, ?HttpClientInterface $http = null)
    {
        $this->http = $http ?? new CurlHttpClient();
    }

    public function send(TestCaseDescriptor $case): ResponseResult
    {
        $url = rtrim($this->baseUrl, '/') . $case->path;
        $method = strtoupper($case->method);

        $headers = [];
        foreach ($case->headers as $name => $value) {
            $headers[$name] = [(string) $value];
        }

        $body = '';
        if ($case->body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = is_array($case->body)
                // Substitute, as InProcessTransport does: a case built to send
                // broken Unicode (the monkey strategy) is a request, not a crash.
                ? json_encode($case->body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
                : (string) $case->body;
            $hasContentType = array_filter(
                array_keys($case->headers),
                static fn(string $k): bool => strcasecmp($k, 'Content-Type') === 0,
            );
            if (empty($hasContentType)) {
                $headers['Content-Type'] = ['application/json'];
            }
        }

        $start = microtime(true);
        try {
            // The server under test answers as it answers: no retry, and a
            // large page is still a page.
            $response = $this->http->send(new HttpRequest($method, $url, $headers, $body), new HttpCallOptions(
                timeout: 10.0,
                maxResponseBytes: 64 * 1024 * 1024,
                retry: RetryPolicy::none(),
            ));
        } catch (HttpTransportException) {
            // No answer at all: status 0 and an empty body (the curl version
            // threw a TypeError here, reading false as the response).
            return new ResponseResult(statusCode: 0, headers: [], body: '', durationMs: round((microtime(true) - $start) * 1000, 2));
        }
        $durationMs = (microtime(true) - $start) * 1000;

        $flat = [];
        foreach ($response->headers as $name => $values) {
            $flat[$name] = (string) end($values);
        }

        return new ResponseResult(
            statusCode: $response->status,
            headers: $flat,
            body: $response->body,
            durationMs: round($durationMs, 2),
        );
    }
}

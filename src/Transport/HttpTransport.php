<?php

declare(strict_types=1);

namespace Semitexa\Testing\Transport;

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
    public function __construct(private readonly string $baseUrl) {}

    public function send(TestCaseDescriptor $case): ResponseResult
    {
        $url = rtrim($this->baseUrl, '/') . $case->path;
        $method = strtoupper($case->method);

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 10,
        ];

        $curlHeaders = [];
        foreach ($case->headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        if ($case->body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = is_array($case->body)
                ? json_encode($case->body, JSON_THROW_ON_ERROR)
                : (string) $case->body;
            $opts[CURLOPT_POSTFIELDS] = $body;
            if (!isset($case->headers['Content-Type'])) {
                $curlHeaders[] = 'Content-Type: application/json';
            }
        }

        if ($curlHeaders) {
            $opts[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        $start = microtime(true);
        $raw = curl_exec($ch);
        $durationMs = (microtime(true) - $start) * 1000;

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return new ResponseResult(
            statusCode: $statusCode,
            headers: $headers,
            body: $body,
            durationMs: round($durationMs, 2),
        );
    }
}

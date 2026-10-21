<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

final readonly class ResponseResult
{
    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public float $durationMs,
        public array $context = [],
    ) {}

    /**
     * A header by name, in any spelling: HttpTransport hands names over
     * lower-cased, InProcessTransport as the application wrote them, so
     * `$headers['Content-Type']` found it through one transport only. Values
     * of a repeated header are joined with ", ", as TestResponse::header()
     * does (HttpTransport has already kept only the last); null when absent.
     */
    public function header(string $name): ?string
    {
        $values = [];
        foreach ($this->headers as $key => $value) {
            // (string): PHP turns a name like "123" into an int key.
            if (strcasecmp((string) $key, $name) === 0) {
                array_push($values, ...(array) $value);
            }
        }

        return $values === [] ? null : implode(', ', $values);
    }
}

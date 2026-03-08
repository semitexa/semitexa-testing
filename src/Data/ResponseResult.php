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
}

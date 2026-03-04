<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

final readonly class TestCaseDescriptor
{
    /**
     * @param array<string, string> $headers
     * @param int|int[]             $expectedStatus
     */
    public function __construct(
        public string $description,
        public string $method,
        public string $path,
        public array $headers,
        public mixed $body,
        public int|array $expectedStatus,
    ) {}
}

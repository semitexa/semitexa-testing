<?php

declare(strict_types=1);

namespace Semitexa\Testing\Contract;

use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Abstraction over how test requests are dispatched.
 *
 * Two implementations are provided:
 *   - InProcessTransport: calls Application::handleRequest() directly (fast, no network).
 *   - HttpTransport: sends real HTTP requests to a running Swoole test server.
 *
 * Strategies are transport-agnostic; they only produce TestCaseDescriptor values.
 */
interface TransportInterface
{
    public function send(TestCaseDescriptor $case): ResponseResult;
}

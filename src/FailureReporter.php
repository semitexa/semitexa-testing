<?php

declare(strict_types=1);

namespace Semitexa\Testing;

use Semitexa\Testing\Contract\TestingStrategyInterface;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;

/**
 * Writes structured JSON failure artifacts to var/test-reports/.
 *
 * Artifacts are designed to be consumed by AI agents in CI pipelines.
 * No hardcoded ai_recommendation_context — the AI reasons from raw structured data.
 */
final class FailureReporter
{
    private readonly string $outputDir;

    public function __construct(string $outputDir = '')
    {
        $this->outputDir = $outputDir ?: $this->detectOutputDir();
    }

    public function report(
        PayloadMetadata $metadata,
        TestingStrategyInterface $strategy,
        TestCaseDescriptor $case,
        ResponseResult $response,
        \Throwable $failure,
    ): void {
        $artifact = [
            'timestamp'      => date('c'),
            'payload'        => $metadata->payloadClass,
            'strategy'       => get_class($strategy),
            'case'           => $case->description,
            'method'         => $case->method,
            'path'           => $case->path,
            'input_body'     => $case->body,
            'expected_status'=> $case->expectedStatus,
            'actual_status'  => $response->statusCode,
            'response_body'  => mb_substr($response->body, 0, 2000), // truncate large bodies
            'duration_ms'    => $response->durationMs,
            'handler_file'   => $this->findHandlerFile($metadata),
            'failure_message'=> $failure->getMessage(),
            'stack_trace'    => $failure->getTraceAsString(),
        ];

        $this->ensureDir($this->outputDir);

        $filename = sprintf(
            '%s/%s_%s_%s.json',
            rtrim($this->outputDir, '/'),
            date('Ymd_His'),
            $this->slugify(basename(str_replace('\\', '/', $metadata->payloadClass))),
            $this->slugify(basename(str_replace('\\', '/', get_class($strategy)))),
        );

        file_put_contents($filename, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Try to locate the Handler file for the given payload (best-effort, not guaranteed).
     * Looks for a Handler class in the same module directory as the Payload.
     */
    private function findHandlerFile(PayloadMetadata $metadata): ?string
    {
        $class = $metadata->payloadClass;
        // e.g. App\Modules\Payment\Application\Payload\PaymentPayload
        // → look for App\Modules\Payment\Application\Handler\PayloadHandler\PaymentHandler
        $handlerGuess = preg_replace('/\\\\Payload\\\\(\w+)Payload$/', '\\\\Event\\\\PayloadHandler\\\\$1Handler', $class);
        if ($handlerGuess && $handlerGuess !== $class && class_exists($handlerGuess)) {
            try {
                return (new \ReflectionClass($handlerGuess))->getFileName() ?: null;
            } catch (\ReflectionException) {}
        }
        return null;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function detectOutputDir(): string
    {
        // Walk up from vendor to project root
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (file_exists($dir . '/composer.json') && !file_exists($dir . '/../../../composer.json')) {
                return $dir . '/var/test-reports';
            }
            $dir = dirname($dir);
        }
        return sys_get_temp_dir() . '/semitexa-test-reports';
    }

    private function slugify(string $str): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $str);
    }
}

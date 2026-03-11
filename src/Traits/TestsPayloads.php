<?php

declare(strict_types=1);

namespace Semitexa\Testing\Traits;

use Semitexa\Testing\Factory\PayloadMetadataFactory;
use Semitexa\Testing\PayloadContractTester;
use Semitexa\Testing\PhpUnitExtension;

/**
 * PHPUnit trait for payload contract testing.
 *
 * Usage:
 * ```php
 * class PaymentPayloadContractTest extends TestCase
 * {
 *     use TestsPayloads;
 *
 *     public function test_payment_payload_contract(): void
 *     {
 *         $this->assertPayloadContract(PaymentPayload::class);
 *     }
 * }
 * ```
 */
trait TestsPayloads
{
    /**
     * Run all declared strategies for the given PayloadDTO class.
     *
     * Reads #[TestablePayload] from the class, merges strategies from
     * #[TestablePayloadPart] traits, then executes via PayloadContractTester.
     *
     * @param class-string $payloadClass
     */
    public function assertPayloadContract(string $payloadClass): void
    {
        $transport = PhpUnitExtension::getTransport();
        $reporter  = PhpUnitExtension::getReporter();

        $tester   = new PayloadContractTester($transport, $reporter);
        PayloadMetadataFactory::clearCache();
        $metadata = PayloadMetadataFactory::create($payloadClass);
        $result   = $tester->run($metadata);

        // Print skipped strategies to stderr (PHPUnit 10 removed addWarning())
        foreach ($result->getSkipped() as $skipped) {
            fwrite(STDERR, "\n  [SKIP] " . basename(str_replace('\\', '/', $skipped->strategyClass)) . ": {$skipped->message}");
        }

        // Fail on any strategy failure (aggregate all into one message)
        $failures = $result->getFailures();
        if (count($failures) > 0) {
            $messages = array_map(
                static fn($f) => "[{$f->strategyClass}] {$f->message}",
                $failures,
            );
            $this->fail(sprintf(
                "%d failure(s) for %s:\n\n%s",
                count($failures),
                $payloadClass,
                implode("\n\n", $messages),
            ));
        }

        // Ensure PHPUnit counts the executed assertions
        $passed = count($result->getPassed());
        if ($passed > 0) {
            $this->addToAssertionCount($passed);
        } elseif (!$result->hasFailures() && count($result->getSkipped()) > 0) {
            $this->markTestSkipped("All strategies were skipped for {$payloadClass}.");
        }
    }

    /**
     * Generate a coverage report for the given PayloadDTO (no assertions made).
     * Prints which fields are covered by which strategies to stdout.
     *
     * @param class-string $payloadClass
     */
    public function printPayloadCoverage(string $payloadClass): void
    {
        $metadata = PayloadMetadataFactory::create($payloadClass);
        $transport = PhpUnitExtension::getTransport();
        $reporter  = PhpUnitExtension::getReporter();
        $tester    = new PayloadContractTester($transport, $reporter);

        echo "\n=== Coverage: {$payloadClass} ===\n";
        echo sprintf("  Path:         %s\n", $metadata->path);
        echo sprintf("  Methods:      %s\n", implode(', ', $metadata->methods));
        echo sprintf("  RequiresAuth: %s\n", $metadata->requiresAuth ? 'yes' : 'no');
        echo sprintf("  Strategies:   %s\n", implode(', ', array_map(
            fn($c) => basename(str_replace('\\', '/', $c)),
            $metadata->strategies,
        )));
        echo "\n  Properties:\n";
        foreach ($metadata->properties as $prop) {
            $optional = ($prop->nullable || $prop->hasDefault) ? ' (optional)' : ' (required)';
            echo sprintf("    %-20s %s%s\n", $prop->name . ':', $prop->type, $optional);
        }
        echo "\n";
    }
}

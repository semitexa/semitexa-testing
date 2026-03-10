<?php

declare(strict_types=1);

namespace Semitexa\Testing;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Semitexa\Core\Application;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Transport\HttpTransport;
use Semitexa\Testing\Transport\InProcessTransport;

/**
 * PHPUnit 10+ Extension (uses Event System, not deprecated Listeners).
 *
 * Configuration via phpunit.xml:
 * ```xml
 * <extensions>
 *     <bootstrap class="Semitexa\Testing\PhpUnitExtension">
 *         <parameter name="transport"   value="in-process"/>  <!-- or "http" -->
 *         <parameter name="base_url"    value="http://localhost:9501"/>
 *         <parameter name="fail_fast"   value="false"/>
 *         <parameter name="report_dir"  value="var/test-reports"/>
 *     </bootstrap>
 * </extensions>
 * ```
 */
final class PhpUnitExtension implements Extension
{
    private static ?TransportInterface $transport = null;
    private static ?FailureReporter $reporter = null;

    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $transportMode = $parameters->has('transport') ? $parameters->get('transport') : 'in-process';
        $baseUrl       = $parameters->has('base_url')  ? $parameters->get('base_url')  : 'http://localhost:9501';
        $reportDir     = $parameters->has('report_dir') ? $parameters->get('report_dir') : '';

        self::$reporter = new FailureReporter($reportDir);

        if ($transportMode === 'http') {
            self::$transport = new HttpTransport($baseUrl);
        } else {
            self::$transport = new InProcessTransport(new Application());
        }
    }

    public static function getTransport(): TransportInterface
    {
        if (self::$transport === null) {
            // Fallback for tests that don't use the extension (e.g. unit tests of strategies)
            try {
                self::$transport = new InProcessTransport(new Application());
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Failed to create InProcessTransport. Ensure PhpUnitExtension is configured in phpunit.xml '
                    . 'or that the application container is bootstrapped before running payload tests. '
                    . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }
        return self::$transport;
    }

    public static function getReporter(): FailureReporter
    {
        if (self::$reporter === null) {
            self::$reporter = new FailureReporter();
        }
        return self::$reporter;
    }
}

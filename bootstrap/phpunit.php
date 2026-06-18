<?php

declare(strict_types=1);

/**
 * Canonical PHPUnit bootstrap for Semitexa projects.
 *
 * Referenced from phpunit.xml.dist as:
 *
 *     bootstrap="vendor/semitexa/testing/bootstrap/phpunit.php"
 *
 * Responsibilities:
 *   1. Load Composer's autoloader.
 *   2. Register runtime PSR-4 mappings for local modules
 *      (src/modules/<Name>/src), so module classes load in tests.
 *   3. Register test PSR-4 mappings for local modules
 *      (src/modules/<Name>/tests).
 *   4. Clear an inherited SEMITEXA_AI_TRACE_ID so the test process is
 *      hermetic. AGENTS.md §4 tells agents to `export SEMITEXA_AI_TRACE_ID`
 *      at task start; if that leaks into PHPUnit, command-under-test runs
 *      append trace events into temp project roots and perturb asserted
 *      output (flaky failures). No test depends on an *inherited* id — the
 *      ones exercising the env fallback set and clear their own.
 *
 * Test namespace registration intentionally lives here — never in root
 * composer autoload.files — to keep production runtime free of test
 * mappings. The runtime registrar is invoked here as well so unit tests
 * that don't bootstrap the framework container can still load module
 * classes.
 *
 * Both registrars are idempotent.
 */

(static function (): void {
    // Hermeticity: no test may inherit an ambient agent trace id (see responsibility 4).
    putenv('SEMITEXA_AI_TRACE_ID');
    unset($_ENV['SEMITEXA_AI_TRACE_ID'], $_SERVER['SEMITEXA_AI_TRACE_ID']);

    $autoload = null;
    $candidates = [];

    $cwd = getcwd();
    if (is_string($cwd) && $cwd !== '') {
        $candidates[] = $cwd . '/vendor/autoload.php';
    }
    if (isset($_SERVER['PWD']) && is_string($_SERVER['PWD']) && $_SERVER['PWD'] !== '') {
        $candidates[] = $_SERVER['PWD'] . '/vendor/autoload.php';
    }

    $current = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $candidates[] = $current . '/vendor/autoload.php';
        $parent = \dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break;
        }
    }

    if ($autoload === null) {
        \fwrite(\STDERR, "[semitexa-testing/bootstrap/phpunit.php] vendor/autoload.php not found.\n");
        exit(1);
    }

    require_once $autoload;

    \Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::register();
    \Semitexa\Testing\Boot\LocalModuleTestAutoloadRegistrar::register();
})();

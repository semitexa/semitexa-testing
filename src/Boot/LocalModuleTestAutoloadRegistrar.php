<?php

declare(strict_types=1);

namespace Semitexa\Testing\Boot;

use Composer\Autoload\ClassLoader;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Registers PSR-4 autoload mappings for local-module test classes.
 *
 *   App\Tests\Modules\<Name>\* -> src/modules/<Name>/tests/
 *
 * Test-only counterpart to Semitexa\Core\Boot\LocalModuleAutoloadRegistrar.
 * Owned by the testing package so production runtime never imports — and
 * never needs to import — test namespaces.
 *
 * Invoked from the canonical phpunit bootstrap shipped by this package
 * (bootstrap/phpunit.php), referenced from the project's phpunit.xml.dist.
 */
final class LocalModuleTestAutoloadRegistrar
{
    private static bool $registered = false;

    public static function register(?string $projectRoot = null): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $root = $projectRoot ?? ProjectRoot::get();
        $modulesDir = $root . '/src/modules';
        if (!is_dir($modulesDir)) {
            return;
        }

        $classLoaders = self::findComposerClassLoaders();
        if ($classLoaders === []) {
            return;
        }

        $entries = scandir($modulesDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $testsDir = $modulesDir . '/' . $entry . '/tests';
            if (!is_dir($testsDir)) {
                continue;
            }
            foreach ($classLoaders as $classLoader) {
                $classLoader->addPsr4("App\\Tests\\Modules\\{$entry}\\", $testsDir);
            }
        }
    }

    /**
     * @return list<ClassLoader>
     */
    private static function findComposerClassLoaders(): array
    {
        $loaders = [];
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
                $loaders[] = $autoloader[0];
            }
        }
        return $loaders;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Testing;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Framework base test case: restores the process-global state a test most
 * commonly leaks — the working directory and the memoized ProjectRoot.
 *
 * The flake this kills: a test chdir()s into a throwaway fixture root and
 * calls ProjectRoot::reset() so discovery sees the fixture; if its tearDown
 * forgets either step, every LATER test in the same process computes paths
 * from the wrong root, and discovery tests start failing by run order. About
 * twenty tests hand-roll this ritual — extending this class makes forgetting
 * impossible, and enterFixtureProjectRoot() replaces the whole boilerplate.
 *
 * Scope rule — what may be reset here: ONLY state that lazily recomputes on
 * next use (ProjectRoot memoizes and refills itself). NEVER add a blanket
 * reset() of a boot-populated registry (SlotHandlerRegistry, anything
 * AttributeDiscovery fills): those are written once per process, so a reset
 * is a one-way door that empties them for every test that follows. A test
 * that must touch one snapshots the static via reflection and restores it in
 * a finally block instead.
 *
 * Subclasses overriding setUp()/tearDown() must call the parent hook, as
 * with any PHPUnit base class.
 */
abstract class TestCase extends PhpUnitTestCase
{
    private ?string $cwdBeforeTest = null;

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwdBeforeTest = getcwd() ?: null;
    }

    protected function tearDown(): void
    {
        // cwd first: ProjectRoot recomputes FROM the working directory, so a
        // reset while still inside a fixture would re-memoize the wrong root.
        if ($this->cwdBeforeTest !== null && getcwd() !== $this->cwdBeforeTest) {
            chdir($this->cwdBeforeTest);
        }
        ProjectRoot::reset();
        foreach ($this->fixtureRoots as $root) {
            $this->removeFixtureDir($root);
        }
        $this->fixtureRoots = [];
        parent::tearDown();
    }

    /**
     * Create a throwaway project root (composer.json + src/modules), chdir
     * into it and point ProjectRoot at it. Cleanup is automatic: tearDown
     * restores the previous cwd, resets ProjectRoot and deletes the fixture.
     *
     * @return string The fixture root path.
     */
    protected function enterFixtureProjectRoot(string $slug = 'fixture'): string
    {
        $root = sys_get_temp_dir() . '/semitexa-test-' . $slug . '-' . uniqid();
        if (!mkdir($root . '/src/modules', 0755, true)) {
            self::fail("Could not create fixture project root at {$root}.");
        }
        // Track the root as soon as it exists, so tearDown deletes it even
        // when one of the steps below fails the test.
        $this->fixtureRoots[] = $root;
        if (file_put_contents($root . '/composer.json', '{"name":"fixture/project"}') === false) {
            self::fail("Could not write composer.json into the fixture root {$root}.");
        }
        if (!chdir($root)) {
            self::fail("Could not chdir into the fixture root {$root}.");
        }
        ProjectRoot::reset();

        return $root;
    }

    private function removeFixtureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

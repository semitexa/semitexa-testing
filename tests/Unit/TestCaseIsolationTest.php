<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Testing\TestCase;

/**
 * Pins the base TestCase's isolation guarantee with a deliberately SLOPPY
 * test: the first method enters a fixture project root and cleans up nothing.
 * The second must still see the original cwd, a recomputed ProjectRoot and no
 * fixture directory on disk. The two methods are order-dependent BY DESIGN —
 * PHPUnit's default declaration order is what makes the leak observable.
 */
final class TestCaseIsolationTest extends TestCase
{
    private static ?string $cwdBeforeSloppyTest = null;
    private static ?string $rootBeforeSloppyTest = null;
    private static ?string $fixtureRoot = null;

    public function test_a_sloppy_test_pollutes_cwd_and_project_root_and_never_cleans_up(): void
    {
        self::$cwdBeforeSloppyTest = getcwd() ?: null;
        self::$rootBeforeSloppyTest = ProjectRoot::get();

        self::$fixtureRoot = $this->enterFixtureProjectRoot('isolation');

        self::assertSame(self::$fixtureRoot, getcwd(), 'the helper chdirs into the fixture');
        self::assertSame(self::$fixtureRoot, ProjectRoot::get(), 'ProjectRoot now resolves to the fixture');
        // ...and deliberately no cleanup: tearDown must do all of it.
    }

    public function test_b_the_next_test_starts_from_a_clean_slate(): void
    {
        self::assertNotNull(self::$fixtureRoot, 'runs after the sloppy test (declaration order)');
        self::assertSame(self::$cwdBeforeSloppyTest, getcwd() ?: null, 'cwd was restored by tearDown');
        self::assertSame(self::$rootBeforeSloppyTest, ProjectRoot::get(), 'ProjectRoot recomputed to the real root');
        self::assertDirectoryDoesNotExist(self::$fixtureRoot, 'the fixture root was deleted');
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Testing\Traits\CallsRoutes;

final class CallsRoutesHeadersTest extends TestCase
{
    use CallsRoutes;

    #[Test]
    public function a_header_the_test_gives_wins_over_the_default_whatever_its_case(): void
    {
        $merge = new \ReflectionMethod(self::class, 'headersOver');

        self::assertSame(
            ['accept' => 'text/html', 'Content-Type' => 'application/json'],
            $merge->invoke(null, ['Accept' => 'application/json', 'Content-Type' => 'application/json'], ['accept' => 'text/html']),
        );
    }
}

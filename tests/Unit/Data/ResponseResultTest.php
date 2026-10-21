<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Data;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Testing\Data\ResponseResult;

final class ResponseResultTest extends TestCase
{
    #[Test]
    public function a_header_is_found_in_either_transport_s_spelling(): void
    {
        $overHttp = new ResponseResult(200, ['content-type' => 'text/html'], '', 0.0);
        $inProcess = new ResponseResult(200, ['Content-Type' => 'text/html'], '', 0.0);

        foreach ([$overHttp, $inProcess] as $result) {
            self::assertSame('text/html', $result->header('Content-Type'));
            self::assertSame('text/html', $result->header('CONTENT-TYPE'));
        }
    }

    #[Test]
    public function a_repeated_header_is_joined_as_test_response_joins_it(): void
    {
        $result = new ResponseResult(200, ['Vary' => ['Accept', 'Cookie'], 'vary' => 'Origin', 'X-Empty' => []], '', 0.0);

        self::assertSame('Accept, Cookie, Origin', $result->header('VARY'), 'every spelling, in order');
        self::assertNull($result->header('X-Empty'));
        self::assertNull($result->header('Location'));
    }

    #[Test]
    public function a_numeric_header_name_does_not_break_the_lookup(): void
    {
        $result = new ResponseResult(200, ['123' => 'x', 'Content-Type' => 'text/plain'], '', 0.0);

        self::assertSame('text/plain', $result->header('content-type'));
        self::assertSame('x', $result->header('123'));
    }
}

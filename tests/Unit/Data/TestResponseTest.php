<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Data;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\HttpResponse;
use Semitexa\Testing\Data\TestResponse;

final class TestResponseTest extends TestCase
{
    #[Test]
    public function it_reads_and_asserts_what_the_application_answered(): void
    {
        $response = new TestResponse(new HttpResponse('{"data":{"items":[{"id":7}]}}', 201, ['Content-Type' => 'application/json']));

        $response->assertStatus(201)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('data.items.0.id', 7)
            ->assertSee('"id":7')
            ->assertDontSee('error');
        self::assertSame(['id' => 7], $response->json('data.items.0'));
        self::assertNull($response->header('X-Missing'));
    }

    #[Test]
    public function a_failed_assertion_says_what_came_back(): void
    {
        $response = new TestResponse(new HttpResponse('{"error":"not_found"}', 404));

        try {
            $response->assertOk();
            self::fail('a 404 passed assertOk');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('not_found', $e->getMessage());
        }

        $this->expectException(AssertionFailedError::class);
        $response->assertJsonPath('data.id', 1);
    }

    #[Test]
    public function a_header_sent_with_no_value_is_absent(): void
    {
        $response = new TestResponse(new HttpResponse('ok', 200, ['X-Trace' => [], 'X-Both' => ['a', 'b']]));

        self::assertNull($response->header('x-trace'));
        self::assertSame('a, b', $response->header('x-both'));
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No X-Trace header in the response.');
        $response->assertHeader('X-Trace');
    }

    /** Only the first spelling was read: 'Vary' and 'vary' came back as the first alone, unlike over HTTP. */
    #[Test]
    public function every_spelling_of_a_header_is_read_together(): void
    {
        $response = new TestResponse(new HttpResponse('ok', 200, ['Vary' => 'Accept', 'vary' => ['Cookie', 'Origin'], 'X-None' => [], 'x-none' => []]));

        self::assertSame(['Accept, Cookie, Origin', null], [$response->header('VARY'), $response->header('X-None')]);
    }

    /** Joined with ', ' two Set-Cookie lines read as one, and an Expires date already holds a comma. */
    #[Test]
    public function each_set_cookie_line_is_read_on_its_own(): void
    {
        $response = new TestResponse(new HttpResponse('ok', 200, ['Set-Cookie' => ['a=1; Expires=Thu, 29 Oct 2026 08:00:00 GMT', 'b=2'], 'set-cookie' => 'c=3']));

        self::assertSame(['a=1; Expires=Thu, 29 Oct 2026 08:00:00 GMT', 'b=2', 'c=3'], $response->headerValues('Set-Cookie'));
        self::assertSame([], $response->headerValues('X-Missing'));
    }

    #[Test]
    public function not_seeing_a_text_in_an_empty_body_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The body is empty, so not seeing 'secret' in it says nothing.");
        (new TestResponse(new HttpResponse('', 200)))->assertDontSee('secret');
    }
}
